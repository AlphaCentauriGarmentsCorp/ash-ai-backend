<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $table = 'storefront_order_items';

    protected $fillable = [
        'order_id', 'product_id', 'product_slug', 'name',
        'size', 'unit_price', 'qty', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'qty' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
