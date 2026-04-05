<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintTypes extends Model
{
    protected $table = 'print_types';
    protected $fillable = [
        'name',
        'description',
        'base_price',
    ];
}
