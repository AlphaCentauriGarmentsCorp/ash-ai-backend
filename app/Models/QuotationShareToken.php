<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationShareToken extends Model
{
    protected $table = 'quotation_share_tokens';

    protected $fillable = [
        'quotation_id',
        'created_by',
        'token',
        'permission',
        'allow_download',
        'expires_at',
        'is_revoked',
        'access_count',
        'last_accessed_at',
        'label',
    ];

    protected $casts = [
        'expires_at'       => 'datetime',
        'last_accessed_at' => 'datetime',
        'is_revoked'       => 'boolean',
        'allow_download'   => 'boolean',
        'access_count'     => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_revoked', false)
                     ->where(function ($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isValid(): bool
    {
        if ($this->is_revoked) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function canDownload(): bool
    {
        return $this->isValid() && $this->allow_download;
    }

    public function canEdit(): bool
    {
        return $this->isValid() && $this->permission === 'edit';
    }

    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }
}

