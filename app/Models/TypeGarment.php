<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeGarment extends Model
{
    protected $table = 'type_garments';

    protected $fillable = [
        'name',
    ];
}
