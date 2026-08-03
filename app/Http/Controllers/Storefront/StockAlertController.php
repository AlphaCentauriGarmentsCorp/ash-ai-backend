<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\StockAlertResource;
use App\Models\Storefront\Product;
use App\Models\Storefront\ProductVariant;
use App\Models\Storefront\StockAlert;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * "Tell me when my size is back."
 *
 * Every route is behind auth and every query is scoped to auth()->id(), so one
 * account can never read or delete another's alerts. The mail itself is not sent
 * from here — a restock is a background event, so StockAlertNotifier and the
 * reefer:notify-back-in-stock command own that half.
 */
class StockAlertController extends Controller
{
    /** GET /stock-alerts — what this account is waiting on, newest first. */
    public function index(): AnonymousResourceCollection
    {
        $alerts = StockAlert::query()
            ->where('user_id', auth()->id())
            // A product can be pulled from the catalog while an alert sits here.
            // Filtered in SQL rather than after the fact so the list never carries a
            // half-null row — same call the cart resource makes about dead lines.
            ->whereHas('variant.product', fn ($q) => $q->where('is_active', true))
            ->with('variant.product')
            // created_at ties when two are added in the same second and engines break
            // that tie differently; the id is insertion order, so it settles it.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return StockAlertResource::collection($alerts);
    }

    /**
     * POST /stock-alerts {slug, size} — start waiting on a size.
     *
     * Refuses anything there is nothing to wait for: an unknown or pulled product, a
     * size that product does not come in, and — loudly — a size that is in stock
     * right now, because silently accepting that would promise a mail that can only
     * arrive after the next sell-out.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'exists:storefront_products,slug'],
            'size' => ['required', 'string', 'max:8'],
        ]);

        $variant = $this->resolveVariant($data['slug'], $data['size']);

        if ($variant->available > 0) {
            throw ValidationException::withMessages([
                'size' => "Size {$data['size']} is in stock right now — no need to wait.",
            ]);
        }

        $existing = StockAlert::query()
            ->where('user_id', auth()->id())
            ->where('product_variant_id', $variant->id)
            ->first();

        if ($existing?->isPending()) {
            return response()->json([
                'message' => 'You are already on the list for that size.',
                'data' => new StockAlertResource($existing->setRelation('variant', $variant)),
            ], 409);
        }

        // A row that was already notified is a spent alert for a restock that has
        // since sold out again. Re-arm it rather than 409ing: the unique index means
        // there is no second row to create, and refusing would lock the account out
        // of ever asking about that size again.
        if ($existing) {
            $existing->forceFill(['notified_at' => null])->save();
            $alert = $existing;
        } else {
            $alert = $this->createAlert($variant);

            // Lost the race with the caller's own double-tap: the other request made
            // the alert, so this one is a duplicate, not a failure.
            if (! $alert) {
                return response()->json([
                    'message' => 'You are already on the list for that size.',
                ], 409);
            }
        }

        return response()->json([
            'message' => "We will email you when size {$variant->size} is back.",
            'data' => new StockAlertResource($alert->setRelation('variant', $variant)),
        ], 201);
    }

    /** DELETE /stock-alerts/{stockAlert} — stop waiting. */
    public function destroy(StockAlert $stockAlert): JsonResponse
    {
        // Binding resolves by id alone, so without this any signed-in user could
        // delete someone else's alert by guessing the number. 404 rather than 403,
        // matching AddressController: a stranger's id stays unconfirmable.
        abort_unless($stockAlert->user_id === auth()->id(), 404);

        $stockAlert->delete();

        return response()->json(['message' => 'Alert removed.']);
    }

    // ------------------------------------------------------------------

    /** Null when the unique index refused the insert — i.e. a concurrent duplicate. */
    private function createAlert(ProductVariant $variant): ?StockAlert
    {
        $alert = new StockAlert();
        $alert->user_id = auth()->id();
        $alert->product_variant_id = $variant->id;

        try {
            $alert->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $alert;
    }

    private function resolveVariant(string $slug, string $size): ProductVariant
    {
        $product = Product::where('slug', $slug)->where('is_active', true)->first();

        // exists:storefront_products,slug passed, so reaching here means the product
        // is deactivated — hidden from the catalog but still guessable.
        if (! $product) {
            throw ValidationException::withMessages([
                'slug' => 'This product is no longer available.',
            ]);
        }

        $variant = $product->variants()->where('size', $size)->first();

        if (! $variant) {
            throw ValidationException::withMessages([
                'size' => "Size {$size} is not available for {$product->name}.",
            ]);
        }

        return $variant->setRelation('product', $product);
    }
}
