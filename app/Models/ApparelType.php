<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApparelType extends Model
{
    protected $table = 'apparel_types';
    protected $fillable = [
        'name',
        'description',
    ];
}
