<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlacementMeasurement extends Model
{
    protected $table = 'placement_measurements';
    protected $fillable = [
        'name',
        'description',
    ];
}
