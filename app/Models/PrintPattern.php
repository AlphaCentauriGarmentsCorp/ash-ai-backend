<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintPattern extends Model
{
    protected $table = 'print_patterns';
    protected $fillable = [
        'name',
        'description',
        'base_price',
    ];
}
