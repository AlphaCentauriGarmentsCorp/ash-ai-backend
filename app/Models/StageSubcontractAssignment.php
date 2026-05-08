<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageSubcontractAssignment extends Model
{
    protected $table = 'stage_subcontract_assignments';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_OUT       = 'out';
    public const STATUS_RETURNED  = 'returned';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'subcontractor_id',
        'quantity_pcs',
        'rate_per_pcs',
        'total_amount',
        'status',
        'sent_at',
        'returned_at',
        'notes',
    ];

    protected $casts = [
        'quantity_pcs' => 'integer',
        'rate_per_pcs' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'sent_at'      => 'datetime',
        'returned_at'  => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'order_stage_id');
    }

    public function subcontractor(): BelongsTo
    {
        // The Eloquent class is named SewingSubcontractor for backward
        // compat (see migration 000001), but its $table now points at
        // the renamed `subcontractors` table.
        return $this->belongsTo(SewingSubcontractor::class, 'subcontractor_id');
    }

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isOut(): bool       { return $this->status === self::STATUS_OUT; }
    public function isReturned(): bool  { return $this->status === self::STATUS_RETURNED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
}
