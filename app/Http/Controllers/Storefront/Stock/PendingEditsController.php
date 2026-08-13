<?php

namespace App\Http\Controllers\Storefront\Stock;

use App\Http\Controllers\Controller;
use App\Models\Storefront\Stock\PendingProductEdit;
use App\Support\Storefront\Stock\InventoryData;
use App\Support\Storefront\Stock\PendingProductEdits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The "Push Product" queue API — port of the Stock manager's
 * PendingEditsController, mounted under /api/stocks/inventory/pending-edits.
 *
 *   GET    .../pending-edits            list the queue
 *   POST   .../pending-edits            queue/overwrite one edit
 *   POST   .../pending-edits/push-all   force-push everything now
 *   POST   .../pending-edits/{id}/push  force-push one row now
 *   DELETE .../pending-edits/{id}       discard a queued edit
 *
 * Only the two human edit surfaces call these (Catalog price/status, Inventory
 * On Hand, and the Catalog's Website View). The service token is refused
 * outright — automated writers must never touch this queue.
 *
 * The behaviour that matters here, and the reason the queue survived a port that
 * deleted the second database it was built for: a queued row is a REVIEW GATE.
 * Applying one now writes straight into the live shop, so what used to be "this
 * will reach the website after the next sync" is "this is on reeferclothing.com
 * the moment somebody presses Force Push". Everything the modal shows — the
 * status pills, "→ ₱X queued", "Queued by <name>" — is unchanged.
 */
class PendingEditsController extends Controller
{
    /**
     * The queue is a humans-only surface. The service token authenticates as
     * role 'service' (see Middleware\Stock\StockAuthenticate); block it here so
     * no sync job can ever be pointed at the queue, even by accident.
     */
    private function refuseServiceToken(Request $request): ?JsonResponse
    {
        $user = $request->attributes->get('authUser');
        if (is_array($user) && ($user['role'] ?? '') === 'service') {
            return response()->json(['error' => 'The Push Product queue is for staff edits only.'], 403);
        }

        return null;
    }

    public function index(Request $request)
    {
        if ($refused = $this->refuseServiceToken($request)) {
            return $refused;
        }

        return response()->json(PendingProductEdits::listAll());
    }

    /**
     * Longest text a single website-content field may queue. Generous for real
     * product copy, small enough to keep the queue and the log readable.
     */
    private const CONTENT_MAX_LENGTH = 2000;

    /**
     * POST — queue one edit (upsert: same sku+field overwrites, last edit wins).
     *
     * Body: { sku, field, new_value, user, reason?, notes? }
     *
     * For website-content fields `sku` carries the DESIGN's product_code —
     * content is shared by every size.
     */
    public function store(Request $request)
    {
        if ($refused = $this->refuseServiceToken($request)) {
            return $refused;
        }

        $sku = trim((string) $request->input('sku', ''));
        $field = (string) $request->input('field', '');
        $newValue = $request->input('new_value');

        if ($sku === '') {
            return response()->json(['error' => 'SKU is required.'], 400);
        }

        $isContent = PendingProductEdits::isContentField($field);
        if (! $isContent && ! in_array($field, PendingProductEdits::FIELDS, true)) {
            return response()->json([
                'error' => 'Field must be one of: '.implode(', ', array_merge(
                    PendingProductEdits::FIELDS, PendingProductEdits::CONTENT_FIELDS
                )),
            ], 400);
        }

        if (in_array($field, ['price', 'available'], true) && (! is_numeric($newValue) || (float) $newValue < 0)) {
            return response()->json([
                'error' => 'Enter a valid '.($field === 'price' ? 'price' : 'quantity').' (0 or more).',
            ], 400);
        }

        if ($isContent && ($error = $this->contentValueError($field, trim((string) $newValue)))) {
            return response()->json(['error' => $error], 400);
        }

        $reason = trim((string) $request->input('reason', ''));
        $notes = trim((string) $request->input('notes', ''));
        $user = $this->actor($request);

        // On Hand keeps its audit rules from the direct-save path: the reason is
        // validated NOW, so the midnight batch can never fail on it.
        if ($field === 'available') {
            if ($reason === '') {
                return response()->json(['error' => 'A reason is required for this adjustment.'], 400);
            }
            if (! in_array($reason, InventoryData::VALID_REASONS, true)) {
                return response()->json(['error' => 'Reason must be one of: '.implode(', ', InventoryData::VALID_REASONS)], 400);
            }
            if ($reason === 'Other' && $notes === '') {
                return response()->json(['error' => 'Please specify the reason when choosing "Other".'], 400);
            }
        }

        try {
            $result = DB::transaction(
                fn () => PendingProductEdits::upsert($sku, $field, $newValue, $user, $reason, $notes)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json($result);
    }

    /**
     * Validation for website-content values.
     *
     * '' normally means "clear this field", and the storefront then falls back
     * to its own copy — with two exceptions that are this schema's, not the
     * reference's, and one whole class of field that this schema cannot hold at
     * all. Both are refused HERE rather than at push time: an edit that is
     * accepted, sits in the queue looking pending, and then quietly fails every
     * night is worse than one that is turned away while the person who typed it
     * is still looking at the screen.
     */
    private function contentValueError(string $field, string $value): ?string
    {
        if (! PendingProductEdits::isSupportedContentField($field)) {
            return 'This shop has nowhere to store "'.$field.'" yet — the products table has no column for it. '
                .'Ask for that column to be added before editing this field.';
        }

        if ($value === '') {
            // products.audience and products.type are NOT NULL: there is no
            // "unset" state for them to fall back to.
            if (in_array($field, PendingProductEdits::REQUIRED_CONTENT_FIELDS, true)) {
                return ucfirst($field).' cannot be cleared on this shop — every product must have one. Pick a value instead.';
            }

            return null;
        }

        if ($field === 'audience' && ! in_array($value, PendingProductEdits::CONTENT_AUDIENCES, true)) {
            return 'Audience must be one of: '.implode(', ', PendingProductEdits::CONTENT_AUDIENCES);
        }
        if ($field === 'type' && ! in_array($value, PendingProductEdits::CONTENT_TYPES, true)) {
            return 'Product type must be one of: '.implode(', ', PendingProductEdits::CONTENT_TYPES);
        }
        if ($field === 'tag' && mb_strlen($value) > 30) {
            return 'Tags are short badges — 30 characters max.';
        }
        if ($field === 'fit_name' && mb_strlen($value) > 60) {
            return 'Fit name is limited to 60 characters.';
        }
        // Photos queue the FILENAME returned by POST /inventory/photo — never a
        // path, so a queued value cannot point outside the photo directory.
        if (in_array($field, ['image_front', 'image_back', 'image_detail'], true)
            && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value)) {
            return 'Photo edits must queue an uploaded filename (use the photo upload first).';
        }
        if (mb_strlen($value) > self::CONTENT_MAX_LENGTH) {
            return 'That text is too long ('.self::CONTENT_MAX_LENGTH.' characters max).';
        }

        return null;
    }

    /** DELETE — discard one queued edit without applying it. */
    public function destroy(Request $request, int $id)
    {
        if ($refused = $this->refuseServiceToken($request)) {
            return $refused;
        }

        $deleted = PendingProductEdit::query()->where('id', $id)->delete();
        if ($deleted === 0) {
            return response()->json(['error' => 'Pending edit not found — it may already have been pushed.'], 404);
        }

        return response()->json(['status' => 'discarded']);
    }

    /** POST {id}/push — Force Push one row, bypassing the midnight schedule. */
    public function push(Request $request, int $id)
    {
        if ($refused = $this->refuseServiceToken($request)) {
            return $refused;
        }

        $pending = DB::table(PendingProductEdits::TABLE)->where('id', $id)->first();
        if ($pending === null) {
            return response()->json(['error' => 'Pending edit not found — it may already have been pushed.'], 404);
        }

        $result = DB::transaction(fn () => PendingProductEdits::applyOne($pending, 'forced'));

        return response()->json(['status' => $result]);
    }

    /**
     * POST push-all — apply the whole queue now. Rows that fail stay queued and
     * come back in `failed`, so nothing disappears silently.
     */
    public function pushAll(Request $request)
    {
        if ($refused = $this->refuseServiceToken($request)) {
            return $refused;
        }

        return response()->json(PendingProductEdits::applyAll('forced'));
    }

    /** Whoever the client claims to be, falling back to the staff session. */
    private function actor(Request $request): string
    {
        $claimed = trim((string) $request->input('user', ''));
        if ($claimed !== '') {
            return $claimed;
        }

        $auth = $request->attributes->get('authUser');

        return is_array($auth) && ($auth['username'] ?? '') !== '' ? (string) $auth['username'] : 'unknown';
    }
}
