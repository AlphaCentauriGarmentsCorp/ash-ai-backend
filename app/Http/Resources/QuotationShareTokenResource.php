<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationShareTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'token'            => $this->token,
            'label'            => $this->label,
            'permission'       => $this->permission,
            'allow_download'   => $this->allow_download,
            'is_revoked'       => $this->is_revoked,
            'is_expired'       => $this->expires_at ? $this->expires_at->isPast() : false,
            'is_valid'         => $this->isValid(),
            'can_download'     => $this->canDownload(),
            'can_edit'         => $this->canEdit(),
            'expires_at'       => $this->expires_at?->toIso8601String(),
            'access_count'     => $this->access_count,
            'last_accessed_at' => $this->last_accessed_at?->toIso8601String(),
            'created_at'       => $this->created_at->toIso8601String(),

            // Full shareable URL for the frontend
            'share_url'        => config('app.frontend_url')
                                    . '/quotations/share/'
                                    . $this->token,
        ];
    }
}
