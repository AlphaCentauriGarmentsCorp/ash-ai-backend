<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Materials extends Model
{

    protected $table = 'materials';
    protected $fillable = [
        'supplier_id',
        'name',
        'material_type',
        'unit',
        'price',
        'minimum',
        'lead',
        'notes',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
