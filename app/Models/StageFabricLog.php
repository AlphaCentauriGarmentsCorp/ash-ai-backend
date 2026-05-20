<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageFabricLog extends Model
{
    protected $table = 'stage_fabric_logs';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'logged_by_user_id',
        'material_type',
        'fabric_used_kg',
        'waste_kg',
        'usable_remaining_kg',
        'fabric_roll_id',
        'notes',
    ];

    protected $casts = [
        'fabric_used_kg'      => 'decimal:2',
        'waste_kg'            => 'decimal:2',
        'usable_remaining_kg' => 'decimal:2',
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
