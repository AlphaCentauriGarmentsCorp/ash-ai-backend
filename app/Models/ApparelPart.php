<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApparelPart extends Model
{
    protected $table = 'apparel_parts';

    protected $fillable = [
        'name',
        'description',
    ];
}
