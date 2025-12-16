<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FabricType extends Model
{
    protected $table = 'type_fabrics';

    protected $fillable = [
        'name',
    ];
}
