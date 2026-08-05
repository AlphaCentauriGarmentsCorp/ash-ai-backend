<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sellable SKU: a product in a single size.
 *
 * Quantities are deliberately split three ways, mirroring the ERP that owns them:
 *
 *   on_hand   physically in the warehouse. The ERP writes it; nothing here does.
 *   allocated spoken for by orders that have not shipped. Checkout writes it.
 *   available on_hand - allocated. What may be sold. Derived, never stored — a
 *             stored copy is a number that can disagree with the other two.
 *
 * Everything that decides "can this be added to a cart / ordered" must read
 * available. Reading on_hand instead oversells whatever is already reserved.
 */
class ProductVariant extends Model
{
    protected $table = 'storefront_product_variants';

    protected $fillable = [
        'product_id', 'size', 'sku', 'on_hand', 'allocated', 'cancelled_qty',
        'weight_grams', 'width_cm', 'length_cm',
        'shelf_location', 'warehouse', 'area', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'allocated' => 'integer',
            'cancelled_qty' => 'integer',
            'weight_grams' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'length_cm' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Sellable quantity. Floored at zero: an over-allocation (the ERP cutting on_hand
     * after orders were taken against it) is a warehouse problem to reconcile, not a
     * negative number for the storefront to do arithmetic with.
     */
    public function getAvailableAttribute(): int
    {
        if (! $this->is_active) {
            return 0;
        }

        return max(0, (int) $this->on_hand - (int) $this->allocated);
    }

    /** "32.5 * 36" — the ERP's own notation, and what the size table shows. */
    public function getDimensionsAttribute(): ?string
    {
        if ($this->width_cm === null || $this->length_cm === null) {
            return null;
        }

        return $this->trimZeros($this->width_cm).' * '.$this->trimZeros($this->length_cm);
    }

    /** Sellable right now — active, and with something left after allocations. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereRaw('on_hand > allocated');
    }

    private function trimZeros(string|float|null $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }
}
