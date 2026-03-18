<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenAssignment extends Model
{
    protected $table = 'screen_assignments';
    protected $fillable = [
        'order_id',
        'placement_id',
        'screen_id',
        'color_index'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function screen()
    {
        return $this->belongsTo(Screens::class, 'screen_id');
    }

    public function placement()
    {
        return $this->belongsTo(OrderDesignPlacement::class, 'placement_id');
    }
}
