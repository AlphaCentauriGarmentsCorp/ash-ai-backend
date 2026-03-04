<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentLocation extends Model
{
    protected $table = 'equipment_location';
    protected $fillable = [
        'name',
        'icon',
        'description',
    ];
}
