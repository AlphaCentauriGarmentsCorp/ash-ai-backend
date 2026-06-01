<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecialPrint extends Model
{
    protected $table = 'special_prints';

    protected $fillable = [
        'name',
        'description',
    ];
}
