<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4 — Stage transition audit log.
 *
 * Immutable record of every workflow stage state change. Written by
 * OrderStagesService hooks. Never updated after insert (Eloquent
 * timestamp-handling is set up to only manage created_at).
 */
class StageAuditLog extends Model
{
    protected $table = 'stage_audit_logs';

    // Immutable: only created_at, no updated_at.
    public const UPDATED_AT = null;

    public const ACTION_STARTED      = 'started';
    public const ACTION_COMPLETED    = 'completed';
    public const ACTION_DELAYED      = 'delayed';
    public const ACTION_ON_HOLD      = 'on_hold';
    public const ACTION_RESUMED      = 'resumed';
    public const ACTION_FOR_APPROVAL = 'for_approval';
    public const ACTION_CANCELLED    = 'cancelled';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'duration_seconds',
        'business_duration_seconds',
        'notes',
        'created_at',
    ];

    protected $casts = [
        'duration_seconds'          => 'integer',
        'business_duration_seconds' => 'integer',
        'created_at'                => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'order_stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
