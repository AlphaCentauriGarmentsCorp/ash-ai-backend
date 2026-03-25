<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Screens extends Model
{

    protected $table = 'screens';
    protected $fillable = [
        'name',
        'size',
        'address',
        'mesh_count',
        'last_maintenance',
        'last_used',
        'total_use',
        'status',
    ];
}
