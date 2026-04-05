<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TshirtNecklines extends Model
{
    protected $table = 'tshirt_necklines';
    protected $fillable = [
        'name',
        'description',
        'base_price',
    ];
}
