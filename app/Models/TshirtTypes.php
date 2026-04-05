<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TshirtTypes extends Model
{
    protected $table = 'tshirt_types';
    protected $fillable = [
        'name',
        'description',
        'base_price',
    ];
}
