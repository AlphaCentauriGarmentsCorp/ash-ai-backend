<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDesign extends Model
{

    protected $table = 'order_designs';
    protected $fillable = [
        'order_id',
        'artist_id',
        'notes',
        'size_label'
    ];

    public function placements()
    {
        return $this->hasMany(OrderDesignPlacement::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
