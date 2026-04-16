<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\QuotationShareToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;

class QuotationShareTokenService
{
    /**
     * Generate a new share token for a given quotation.
     *
     * @param  int    $quotationId
     * @param  array  $options {
     *     permission?:     'view'|'edit',
     *     allow_download?: bool,
     *     expires_at?:     string|null,
     *     label?:          string|null,
     * }
     */
    public function generate(int $quotationId, array $options = []): QuotationShareToken
    {
        $quotation = Quotation::where('id', $quotationId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return QuotationShareToken::create([
            'quotation_id'   => $quotation->id,
            'created_by'     => Auth::id(),
            'token'          => $this->generateUniqueToken(),
            'permission'     => $options['permission']     ?? 'view',
            'allow_download' => $options['allow_download'] ?? false,
            'expires_at'     => $options['expires_at']     ?? null,
            'label'          => $options['label']          ?? null,
        ]);
    }

    /**
     * List all tokens for a quotation owned by the authenticated user.
     */
    public function listForQuotation(int $quotationId): Collection
    {
        $quotation = Quotation::where('id', $quotationId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return QuotationShareToken::where('quotation_id', $quotation->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Revoke a specific token owned by the authenticated user.
     */
    public function revoke(string $token): QuotationShareToken
    {
        $shareToken = QuotationShareToken::where('token', $token)
            ->where('created_by', Auth::id())
            ->firstOrFail();

        $shareToken->update(['is_revoked' => true]);

        return $shareToken->fresh();
    }

    /**
     * Revoke ALL active tokens for a quotation.
     */
    public function revokeAll(int $quotationId): int
    {
        $quotation = Quotation::where('id', $quotationId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return QuotationShareToken::where('quotation_id', $quotation->id)
            ->where('is_revoked', false)
            ->update(['is_revoked' => true]);
    }

    /**
     * Resolve a public share token for READ access (view or edit).
     * Records the access hit.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function resolvePublicToken(string $token): array
    {
        $shareToken = QuotationShareToken::with('quotation')
            ->where('token', $token)
            ->first();

        if (!$shareToken) {
            abort(404, 'Share link not found.');
        }

        if (!$shareToken->isValid()) {
            abort(403, 'This share link has expired or been revoked.');
        }

        $shareToken->recordAccess();

        return [
            'token'      => $shareToken,
            'quotation'  => $shareToken->quotation,
            'permission' => $shareToken->permission,
        ];
    }

    /**
     * Resolve a public share token specifically for EDIT access.
     * Throws 403 if the token exists but has no edit permission.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function resolveEditToken(string $token): array
    {
        $shareToken = QuotationShareToken::with('quotation')
            ->where('token', $token)
            ->first();

        if (!$shareToken) {
            abort(404, 'Share link not found.');
        }

        if (!$shareToken->isValid()) {
            abort(403, 'This share link has expired or been revoked.');
        }

        if (!$shareToken->canEdit()) {
            abort(403, 'This share link does not grant edit access.');
        }

        $shareToken->recordAccess();

        return [
            'token'     => $shareToken,
            'quotation' => $shareToken->quotation,
        ];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function generateUniqueToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (QuotationShareToken::where('token', $token)->exists());

        return $token;
    }
}
