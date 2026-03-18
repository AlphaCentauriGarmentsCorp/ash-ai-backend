<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStage extends Model
{
    protected $table = 'order_stages';
    protected $fillable = [
        'order_id',
        'stage',
        'status'
    ];

    // protected $casts = [
    //     'stages' => 'array',
    // ];
}
