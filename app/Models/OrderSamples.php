<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSamples extends Model
{
    protected $table = 'order_samples';
    protected $fillable = [
        'order_id',
        'size',
        'quantity',
        'total_price',
        'unit_price'
    ];
}
