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
        * Update print parts via a valid edit-permission token.
     *
     * PUT /v2/share/quotations/{token}
     *
     * Accepted fields:
          *   print_parts or print_parts_json            (JSON string or array)
        *   print_parts_files[index]                   (file upload, optional)
     *
     * Triggers PDF regeneration and email notification (same as private update).
     */
    public function update(PublicUpdateRequest $request, string $token)
    {
        ['quotation' => $quotation] = $this->shareService->resolveEditToken($token);

        $validated = $request->validated();
        $data = [];

        $incomingPrintParts = $request->input('print_parts');
        $incomingPrintPartsJson = null;

        if ($incomingPrintParts === null || $incomingPrintParts === '') {
            $incomingPrintParts = $request->input('print_parts_json');
            if (array_key_exists('print_parts_json', $validated) && is_string($validated['print_parts_json']) && $validated['print_parts_json'] !== '') {
                $incomingPrintPartsJson = $validated['print_parts_json'];
            }
        } elseif (array_key_exists('print_parts', $validated) && is_string($validated['print_parts']) && $validated['print_parts'] !== '') {
            $incomingPrintPartsJson = $validated['print_parts'];
        }

        if (is_array($incomingPrintParts)) {
            if (! array_is_list($incomingPrintParts)) {
                $incomingPrintParts = array_values($incomingPrintParts);
            }

            $data['print_parts'] = $incomingPrintParts;
            $data['print_parts_json'] = json_encode($incomingPrintParts);
        } elseif (is_string($incomingPrintPartsJson) && $incomingPrintPartsJson !== '') {
            $data['print_parts_json'] = $incomingPrintPartsJson;
            $decoded = json_decode($incomingPrintPartsJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data['print_parts'] = $decoded;
            }
        }

        $updated = $this->quotationService->update($data, $quotation->id, $request);

        return response()->json([
            'message' => 'Quotation updated successfully.',
            'data' => new PublicQuotationResource($updated),
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
