<?php

namespace App\Http\Resources\Storefront;

use App\Models\Storefront\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Storefront\Cart
 */
class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->items
            // A variant can disappear from under a cart (product pulled from the
            // catalog). Drop those lines rather than emitting a half-null item the
            // frontend has to defend against.
            ->filter(fn (CartItem $item) => $item->variant && $item->variant->product)
            ->map(fn (CartItem $item) => $this->line($item))
            ->values();

        $subtotal = $this->subtotal();
        $selectedSubtotal = $this->selectedSubtotal();
        $selectedCount = $this->selectedCount();

        return [
            'items' => $items,

            // Two different totals, because two different questions:
            //   count/subtotal          — everything in the cart. The nav badge.
            //   selected_*              — only the ticked lines. The order summary,
            //                             and what checkout will actually charge.
            'count' => $this->itemCount(),
            'subtotal' => $subtotal,
            'subtotal_formatted' => '₱'.number_format($subtotal),

            'selected_count' => $selectedCount,
            'selected_subtotal' => $selectedSubtotal,
            'selected_subtotal_formatted' => '₱'.number_format($selectedSubtotal),

            // Drives the "select all" checkbox. Vacuously true on an empty cart
            // would render it ticked with nothing to tick, so require items.
            'all_selected' => $items->isNotEmpty() && $items->every(fn ($line) => $line['selected']),
        ];
    }

    private function line(CartItem $item): array
    {
        $variant = $item->variant;
        $product = $variant->product;

        // Priced from the catalog on every read. The cart stores a pointer and a
        // quantity — never a price — so a catalog change is reflected immediately
        // instead of the shopper seeing a total the checkout will not honour.
        $unitPrice = (int) $product->price;

        return [
            'id' => $item->id,
            'selected' => (bool) $item->selected,
            // 'key' matches the frontend's existing 'slug|size' cart identity.
            'key' => $product->slug.'|'.$variant->size,
            'slug' => $product->slug,
            'size' => $variant->size,
            'name' => $product->name,
            'qty' => $item->qty,
            'unit_price' => $unitPrice,
            'unit_price_formatted' => '₱'.number_format($unitPrice),
            'line_total' => $unitPrice * $item->qty,
            'line_total_formatted' => '₱'.number_format($unitPrice * $item->qty),
            'image' => $product->image_path ? asset('storage/'.$product->image_path) : null,

            // Stock is reported, not enforced, at cart level: nothing is reserved
            // until checkout, so a line can legitimately go stale while it sits
            // here. The UI needs to be able to say so.
            // available, not on_hand: what is left after other people's unshipped
            // orders is the only number a shopper can actually buy against.
            'stock' => $variant->available,
            'in_stock' => $variant->available > 0,
            'exceeds_stock' => $item->qty > $variant->available,
        ];
    }
}
