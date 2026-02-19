<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatternType extends Model
{
    protected $table = 'pattern_types';
    protected $fillable = [
        'name',
        'description',
    ];
}
