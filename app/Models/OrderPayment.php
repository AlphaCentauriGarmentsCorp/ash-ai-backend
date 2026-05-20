<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * OrderPayment — per-payment event record.
 *
 * Distinct from PaymentMethods (which is the channel lookup —
 * GCash/BPI/Cash/etc.). This is a real transaction-level row.
 *
 * State machine (lives in OrderPaymentService):
 *
 *   waiting  ── upload proof ──→  for_verification
 *                                       │
 *                            (Finance verifies)
 *                                       │
 *                              ┌────────┴────────┐
 *                          verified           rejected
 *                                              (with reason)
 *
 * Verification is gated on `action.verify-payment` (Finance + Super Admin
 * only — NOT CSR). The gate lives in OrderPaymentService::verify().
 */
class OrderPayment extends Model
{
    protected $table = 'order_payments';

    protected $fillable = [
        'order_id',
        'payment_type',
        'amount',
        'payment_method_id',
        'reference_number',
        'proof_path',
        'status',
        'uploaded_by_user_id',
        'uploaded_at',
        'verified_by_user_id',
        'verified_at',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'uploaded_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    // Payment type constants
    public const TYPE_SAMPLE       = 'sample';
    public const TYPE_DOWN_PAYMENT = 'down_payment';
    public const TYPE_BALANCE      = 'balance';
    public const TYPE_FULL         = 'full';

    public const TYPES = [
        self::TYPE_SAMPLE,
        self::TYPE_DOWN_PAYMENT,
        self::TYPE_BALANCE,
        self::TYPE_FULL,
    ];

    // Status constants
    public const STATUS_WAITING          = 'waiting';
    public const STATUS_FOR_VERIFICATION = 'for_verification';
    public const STATUS_VERIFIED         = 'verified';
    public const STATUS_REJECTED         = 'rejected';

    public const STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_FOR_VERIFICATION,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethods::class, 'payment_method_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
