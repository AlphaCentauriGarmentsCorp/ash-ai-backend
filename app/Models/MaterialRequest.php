<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 3 — Material Request
 *
 * Created by production-role users during their order's active
 * workflow stage. Status flow: pending → approved | rejected | auto_pr.
 */
class MaterialRequest extends Model
{
    protected $table = 'material_requests';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_AUTO_PR  = 'auto_pr';

    protected $fillable = [
        'mr_code',
        'order_id',
        'stage_id',
        'requested_by_user_id',
        'status',
        'reason',
        'rejection_reason',
        'approved_by_user_id',
        'approved_at',
        'purchase_request_id',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'stage_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_AUTO_PR,
        ], true);
    }
}
