<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentInventory extends Model
{
    protected $table = 'equipment_inventory';
    protected $fillable = [
        'location_id',
        'sku',
        'name',
        'quantity',
        'color',
        'model',
        'material',
        'price',
        'penalty',
        'design',
        'description',
        'image',
        'receipt',
        'qr_code',
        'in_use',
        'missing',
        'status',
    ];

    public function location()
    {
        return $this->belongsTo(EquipmentLocation::class, 'location_id', 'id');
    }
}
