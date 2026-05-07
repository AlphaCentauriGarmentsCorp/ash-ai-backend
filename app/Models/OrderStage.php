<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderStage – represents one step in an Order's sequential workflow.
 *
 * Status lifecycle (see ASH AI master brief §12):
 *   pending → in_progress → for_approval → completed
 *                       ↘ delayed / on_hold / rejected (terminal-ish)
 *
 * Stages are created in bulk when an Order is stored (see OrderService::store)
 * and progressed one at a time by the OrderStagesService::markComplete() helper.
 */
class OrderStage extends Model
{
    protected $table = 'order_stages';

    protected $fillable = [
        'order_id',
        'stage',
        'sequence',
        'status',
        'started_at',
        'completed_at',
        'delayed_at',
        'assigned_to',
        'assigned_role',
        'notes',
    ];

    protected $casts = [
        'sequence'     => 'integer',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'delayed_at'   => 'datetime',
    ];

    // ---- Status constants ------------------------------------------------
    public const STATUS_PENDING      = 'pending';
    public const STATUS_IN_PROGRESS  = 'in_progress';
    public const STATUS_FOR_APPROVAL = 'for_approval';
    public const STATUS_COMPLETED    = 'completed';
    public const STATUS_DELAYED      = 'delayed';
    public const STATUS_ON_HOLD      = 'on_hold';
    public const STATUS_REJECTED     = 'rejected';

    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_FOR_APPROVAL,
            self::STATUS_COMPLETED,
            self::STATUS_DELAYED,
            self::STATUS_ON_HOLD,
            self::STATUS_REJECTED,
        ];
    }

    // ---- Relations -------------------------------------------------------
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // ---- Helpers ---------------------------------------------------------
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Computes how long this stage has been worked on (in minutes).
     * Returns null when the stage has not started yet.
     */
    public function durationMinutes(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        $end = $this->completed_at ?: now();
        return $this->started_at->diffInMinutes($end);
    }
}
