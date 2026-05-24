<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApparelPatternPrice extends Model
{
    protected $table = 'apparel_pattern_prices';
    protected $fillable = [
        'apparel_type_id',
        'pattern_type_id',
        'apparel_type_name',
        'pattern_type_name',
        'price',
        'size_prices',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'size_prices' => 'array',
    ];

    /**
     * Resolve the base price for a given size name.
     *
     * Looks up size_prices (case-insensitive on the size name). Falls back to
     * the legacy single `price` when the size has no specific entry, so
     * existing rows without per-size data keep working.
     */
    public function priceForSize(?string $size): float
    {
        $map = is_array($this->size_prices) ? $this->size_prices : [];

        if ($size !== null && $map !== []) {
            // Direct hit first.
            if (array_key_exists($size, $map)) {
                return (float) $map[$size];
            }

            // Case-insensitive match (e.g. "small" vs "Small").
            $needle = strtolower(trim($size));
            foreach ($map as $key => $value) {
                if (strtolower(trim((string) $key)) === $needle) {
                    return (float) $value;
                }
            }
        }

        return (float) ($this->price ?? 0);
    }
}