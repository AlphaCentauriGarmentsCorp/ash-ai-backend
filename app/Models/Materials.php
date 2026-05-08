<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Materials extends Model
{
    protected $table = 'materials';

    protected $fillable = [
        'supplier_id',
        'name',
        'material_type',
        'unit',
        'price',
        'stock_on_hand',
        'minimum',
        'lead',
        'notes',
    ];

    protected $casts = [
        'price'         => 'decimal:2',
        'stock_on_hand' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function materialRequestItems(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class, 'material_id');
    }

    public function purchaseRequestItems(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class, 'material_id');
    }
}
