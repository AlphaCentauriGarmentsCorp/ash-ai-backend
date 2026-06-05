<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FabricType extends Model
{
    protected $table = 'fabric_types';

    protected $fillable = [
        'name',
        'description',
    ];
}
