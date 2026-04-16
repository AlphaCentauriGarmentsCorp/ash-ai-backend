<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuotationShare\PublicUpdateRequest;
use App\Http\Resources\PublicQuotationResource;
use App\Services\QuotationService;
use App\Services\QuotationShareTokenService;
use Illuminate\Support\Facades\Storage;

/**
 * Public controller — NO authentication required.
 *
 * Access is controlled entirely by the share token in the URL.
 *
 * GET  /v2/share/quotations/{token}       → view filtered quotation data
 * PUT  /v2/share/quotations/{token}       → update items & print parts (edit permission required)
 * GET  /v2/share/quotations/{token}/pdf   → download PDF (allow_download toggle required)
 */
class PublicQuotationController extends Controller
{
    public function __construct(
        protected QuotationShareTokenService $shareService,
        protected QuotationService $quotationService,
    ) {}

    /**
     * Return the filtered public quotation data for a valid share token.
     *
     * GET /v2/share/quotations/{token}
     */
    public function show(string $token)
    {
        ['quotation' => $quotation, 'token' => $shareToken] =
            $this->shareService->resolvePublicToken($token);

        return response()->json([
            'data' => new PublicQuotationResource($quotation),
            'meta' => [
                'permission'     => $shareToken->permission,
                'can_download'   => $shareToken->canDownload(),
                'can_edit'       => $shareToken->canEdit(),
                'expires_at'     => $shareToken->expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update items_json and/or print_parts_json via a valid edit-permission token.
     *
     * PUT /v2/share/quotations/{token}
     *
     * Accepted fields:
     *   items_json[].name, items_json[].quantity   (price is NOT accepted)
     *   print_parts_json[].part
     *   print_parts_json[].color_count
     *   print_parts_json[].image                   (file upload, optional)
     *   print_parts_json[].existing_image          (keep current image path)
     *
     * Triggers PDF regeneration and email notification (same as private update).
     */
    public function update(PublicUpdateRequest $request, string $token)
    {
        ['quotation' => $quotation] =
            $this->shareService->resolveEditToken($token);

        $data = [];

        // ── Items — build from validated input, no price field accepted ───────
        if ($request->has('items_json')) {
            $data['items_json'] = array_map(function ($item) {
                return [
                    'name'     => $item['name'],
                    'quantity' => $item['quantity'],
                ];
            }, $request->input('items_json'));
        }

        // ── Print parts — pass through with file handling in service ──────────
        if ($request->has('print_parts_json')) {
            $data['print_parts_json'] = $request->input('print_parts_json');
        }

        // Delegate to the existing QuotationService::update()
        // This triggers PDF regeneration and email notification automatically
        $updated = $this->quotationService->update($data, $quotation->id, $request);

        return response()->json([
            'message' => 'Quotation updated successfully.',
            'data'    => new PublicQuotationResource($updated),
        ]);
    }

    /**
     * Serve the PDF for a valid token with allow_download enabled.
     *
     * GET /v2/share/quotations/{token}/pdf
     */
    public function pdf(string $token)
    {
        ['quotation' => $quotation, 'token' => $shareToken] =
            $this->shareService->resolvePublicToken($token);

        if (!$shareToken->canDownload()) {
            return response()->json([
                'message' => 'This share link does not grant download access.',
            ], 403);
        }

        $fileName = $quotation->quotation_id . '.pdf';
        $filePath = "quotations/{$fileName}";

        if (!Storage::disk('public')->exists($filePath)) {
            return response()->json(['message' => 'PDF not found.'], 404);
        }

        $fullPath = Storage::disk('public')->path($filePath);

        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$fileName}\"",
        ]);
    }
}
