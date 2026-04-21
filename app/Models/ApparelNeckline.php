<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApparelNeckline extends Model
{
    protected $table = 'apparel_necklines';

    protected $fillable = [
        'name',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
