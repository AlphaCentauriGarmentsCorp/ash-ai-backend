<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageWasteLog extends Model
{
    protected $table = 'stage_waste_logs';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'logged_by_user_id',
        'quantity_pcs',
        'photo_path',
        'notes',
    ];

    protected $casts = [
        'quantity_pcs' => 'integer',
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
