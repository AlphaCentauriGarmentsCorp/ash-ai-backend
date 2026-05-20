<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageRejectLog extends Model
{
    protected $table = 'stage_reject_logs';

    public const DISPOSITION_REJECT = 'reject';
    public const DISPOSITION_REPAIR = 'repair';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'logged_by_user_id',
        'quantity_pcs',
        'disposition',        // Phase 7-B: reject | repair
        'reject_reason_id',   // Phase 7-B: FK to reject_reasons (nullable)
        'photo_path',
        'notes',
    ];

    protected $casts = [
        'quantity_pcs' => 'integer',
    ];

    protected $attributes = [
        'disposition' => self::DISPOSITION_REJECT,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'order_stage_id');
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_user_id');
    }

    /** Phase 7-B: the reason cited from the reject_reasons lookup. */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(RejectReason::class, 'reject_reason_id');
    }

    public function isReject(): bool
    {
        return $this->disposition === self::DISPOSITION_REJECT;
    }

    public function isRepair(): bool
    {
        return $this->disposition === self::DISPOSITION_REPAIR;
    }
}
