<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 3 — Purchase Request
 *
 * Lifecycle: pending → approved → ordered → received.
 * Cancellable from any state before received.
 */
class PurchaseRequest extends Model
{
    protected $table = 'purchase_requests';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_ORDERED   = 'ordered';
    public const STATUS_RECEIVED  = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'pr_code',
        'order_id',
        'material_request_id',
        'supplier_id',
        'status',
        'total_amount',
        'reason',
        'approved_by_user_id',
        'approved_at',
        'ordered_at',
        'received_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'approved_at'  => 'datetime',
        'ordered_at'   => 'datetime',
        'received_at'  => 'datetime',
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool  { return $this->status === self::STATUS_APPROVED; }
    public function isOrdered(): bool   { return $this->status === self::STATUS_ORDERED; }
    public function isReceived(): bool  { return $this->status === self::STATUS_RECEIVED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
}
