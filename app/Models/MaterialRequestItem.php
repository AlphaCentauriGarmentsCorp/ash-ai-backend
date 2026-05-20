<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequestItem extends Model
{
    protected $table = 'material_request_items';

    protected $fillable = [
        'material_request_id',
        'material_id',
        'quantity_requested',
        'quantity_available',
        'quantity_short',
        'unit',
        'notes',
    ];

    protected $casts = [
        'quantity_requested' => 'decimal:2',
        'quantity_available' => 'decimal:2',
        'quantity_short'     => 'decimal:2',
    ];

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Materials::class, 'material_id');
    }

    public function hasShortage(): bool
    {
        return (float) $this->quantity_short > 0;
    }
}
