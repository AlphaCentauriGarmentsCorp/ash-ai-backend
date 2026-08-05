<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequestItem extends Model
{
    protected $table = 'storefront_return_request_items';

    // return_request_id comes from the relationship.
    protected $fillable = [
        'order_item_id', 'qty',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
        ];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** Priced off the order line the customer paid, not off today's catalog. */
    public function lineTotal(): int
    {
        return (int) ($this->orderItem?->unit_price ?? 0) * $this->qty;
    }
}
