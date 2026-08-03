<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $table = 'storefront_cart_items';

    protected $fillable = [
        'product_variant_id', 'qty', 'selected',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'selected' => 'boolean',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
