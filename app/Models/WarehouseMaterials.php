<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseMaterials extends Model
{
    protected $table = 'warehouse_materials';

    protected $fillable = [
        'material_name',   
        'brand',
        'category',
        'type',
        'unit',
        'quantity',
        'cost_per_unit',
    ];
}
