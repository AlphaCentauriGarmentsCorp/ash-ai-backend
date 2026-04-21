<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApparelPatternPrice extends Model
{
    protected $table = 'apparel_pattern_prices';
    protected $fillable = [
        'apparel_type_id',
        'pattern_type_id',
        'apparel_type_name',
        'pattern_type_name',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
