<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'storefront_products';

    protected $fillable = [
        'slug', 'name', 'audience', 'type', 'price', 'tag',
        'blurb', 'material', 'fit_name', 'fit_desc',
        'image_path', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Has this account actually bought this product?
     *
     * Proved against order_items, not a flag on the review — the orders table is the
     * only thing that knows. Matched on product_id rather than the product_slug
     * snapshot: the slug is a name, and a name can be reused by a different product,
     * whereas the id is identity. (product_id is nullOnDelete, but a deleted product
     * has no page to review anyway.)
     *
     * Any order the account placed counts, including an unpaid COD one: they went
     * through checkout and committed to buying it. Declined payments never create an
     * order at all, so there is nothing to exclude.
     */
    public function wasPurchasedBy(?Customer $user): bool
    {
        if (! $user) {
            return false;
        }

        return OrderItem::query()
            ->where('product_id', $this->id)
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->exists();
    }

    /** Sizes available, ordered S,M,L,XL,2XL,OS. */
    public function getSizesAttribute(): array
    {
        $order = ['S' => 0, 'M' => 1, 'L' => 2, 'XL' => 3, '2XL' => 4, 'OS' => 5];

        // Every size the product comes in, whether or not it can be bought today.
        // The picker greys out what is unavailable rather than hiding it: a gap in
        // the size run reads as "they don't make my size", where a greyed-out chip
        // reads as "my size is out" — and that one is worth a back-in-stock alert.
        // `available` is 0 for both an inactive and a sold-out size, so the same
        // disabled treatment covers both.
        return $this->variants
            ->sortBy(fn ($v) => $order[$v->size] ?? 99)
            ->pluck('size')
            ->values()
            ->all();
    }

    /**
     * Sellable across every size — available, not on_hand. A product whose whole
     * warehouse count is already allocated to unshipped orders has nothing to sell,
     * and must read as sold out rather than in stock.
     */
    public function getTotalStockAttribute(): int
    {
        return (int) $this->variants->sum(fn (ProductVariant $v) => $v->available);
    }

    /** Drives the "ONLY A FEW LEFT" label. */
    public function getLowStockAttribute(): bool
    {
        return $this->total_stock > 0
            && $this->total_stock <= (int) config('reefer.low_stock_threshold');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
