<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageInkLog extends Model
{
    protected $table = 'stage_ink_logs';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'logged_by_user_id',
        'ink_color',
        'ink_used_kg',
        'ink_waste_kg',
        'usable_remaining_kg',
        'notes',
    ];

    protected $casts = [
        'ink_used_kg'         => 'decimal:3',
        'ink_waste_kg'        => 'decimal:3',
        'usable_remaining_kg' => 'decimal:3',
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
}
