<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue 12 — Quotation status transition audit log.
 *
 * Immutable record of every quotation status change. Written by
 * QuotationService (changeStatus + confirmAndConvert). Never updated after
 * insert — like StageAuditLog, only created_at is managed.
 */
class QuotationStatusLog extends Model
{
    protected $table = 'quotation_status_logs';

    // Immutable: only created_at, no updated_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'quotation_id',
        'user_id',
        'from_status',
        'to_status',
        'notes',
        'email_sent',
        'created_at',
    ];

    protected $casts = [
        'email_sent' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
