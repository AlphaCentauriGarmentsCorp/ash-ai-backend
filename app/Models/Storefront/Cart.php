<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $table = 'storefront_carts';

    // user_id is set through the relationship, never from client input.
    protected $fillable = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Eager-load everything a cart needs to be priced in one go: without this the
     * resource would issue two queries per line to reach the product.
     */
    public function loadLines(): static
    {
        return $this->load(['items' => fn ($q) => $q->with('variant.product')->orderBy('id')]);
    }

    /** Only the lines ticked for checkout. */
    public function selectedItems(): Collection
    {
        return $this->items->where('selected', true);
    }

    /** Live subtotal in whole pesos, priced from the catalog — never from the cart row. */
    public function subtotal(): int
    {
        return $this->sumOf($this->items);
    }

    /**
     * What the shopper is actually about to pay for. This — not subtotal() — is the
     * number the order summary and checkout must use; the rest of the cart is a
     * save-for-later list.
     */
    public function selectedSubtotal(): int
    {
        return $this->sumOf($this->selectedItems());
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('qty');
    }

    public function selectedCount(): int
    {
        return (int) $this->selectedItems()->sum('qty');
    }

    private function sumOf(Collection $items): int
    {
        return (int) $items->sum(
            fn (CartItem $item) => ($item->variant?->product?->price ?? 0) * $item->qty
        );
    }
}
