<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Storefront\StockAlert
 */
class StockAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variant = $this->variant;
        $product = $variant->product;

        return [
            'id' => $this->id,
            'slug' => $product->slug,
            'size' => $variant->size,
            'product_name' => $product->name,
            // Priced from the catalog on every read, like the cart: the alert stores
            // a pointer, never a price, so a repricing shows up immediately.
            'price_formatted' => '₱'.number_format((int) $product->price),
            'image' => $product->image_path ? asset('storage/'.$product->image_path) : null,

            // The list is a list of things NOT in stock, so this is normally false —
            // it goes true in the window between a restock and the notify job, and
            // the UI can say "it's back" instead of "waiting".
            'in_stock' => $variant->available > 0,

            'created_at' => optional($this->created_at)->toIso8601String(),
            'notified_at' => optional($this->notified_at)->toIso8601String(),
        ];
    }
}
