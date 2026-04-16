<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuotationShare\GenerateTokenRequest;
use App\Http\Resources\QuotationShareTokenResource;
use App\Services\QuotationShareTokenService;
use Illuminate\Http\JsonResponse;

/**
 * Authenticated controller for managing quotation share tokens.
 *
 * All routes require auth:sanctum.
 *
 * POST   /v2/quotations/{id}/share          → generate token
 * GET    /v2/quotations/{id}/share          → list all tokens for a quotation
 * DELETE /v2/quotations/{id}/share          → revoke ALL tokens for a quotation
 * DELETE /v2/quotations/share/{token}       → revoke a specific token
 *
 * Token permissions:
 *   permission: 'view'  → read-only access to filtered public data
 *   permission: 'edit'  → read + update items and print parts via public PUT
 *   allow_download: bool → independent toggle for PDF download on any permission level
 */
class QuotationShareController extends Controller
{
    public function __construct(
        protected QuotationShareTokenService $service
    ) {}

    /**
     * Generate a new shareable token for a quotation.
     *
     * POST /v2/quotations/{id}/share
     *
     * Body (all optional):
     * {
     *   "permission":     "view" | "edit",     default: "view"
     *   "allow_download": true | false,         default: false
     *   "expires_at":     "2026-12-31 23:59:59" | null,
     *   "label":          "Client review link"
     * }
     */
    public function generate(GenerateTokenRequest $request, int $id): JsonResponse
    {
        $token = $this->service->generate($id, $request->validated());

        return response()->json([
            'message' => 'Share link generated successfully.',
            'data'    => new QuotationShareTokenResource($token),
        ], 201);
    }

    /**
     * List all share tokens for a quotation.
     *
     * GET /v2/quotations/{id}/share
     */
    public function index(int $id): JsonResponse
    {
        $tokens = $this->service->listForQuotation($id);

        return response()->json([
            'data' => QuotationShareTokenResource::collection($tokens),
        ]);
    }

    /**
     * Revoke a specific share token.
     *
     * DELETE /v2/quotations/share/{token}
     */
    public function revoke(string $token): JsonResponse
    {
        $shareToken = $this->service->revoke($token);

        return response()->json([
            'message' => 'Share link has been revoked.',
            'data'    => new QuotationShareTokenResource($shareToken),
        ]);
    }

    /**
     * Revoke ALL active tokens for a quotation.
     *
     * DELETE /v2/quotations/{id}/share
     */
    public function revokeAll(int $id): JsonResponse
    {
        $count = $this->service->revokeAll($id);

        return response()->json([
            'message' => "{$count} share link(s) revoked successfully.",
        ]);
    }
}
