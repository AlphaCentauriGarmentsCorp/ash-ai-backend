<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single Superadmin-editable pricing rate (key/value).
 *
 * The pricing engine reads rates through the static helper `rate()` so it
 * never hardcodes a number. If a key is missing (e.g. a fresh DB), the
 * caller supplies a sensible default so quoting never hard-fails.
 */
class PricingSetting extends Model
{
    protected $table = 'pricing_settings';

    protected $fillable = [
        'key',
        'label',
        'value',
        'unit',
        'group',
        'description',
    ];

    protected $casts = [
        'value' => 'decimal:2',
    ];

    /**
     * Known rate keys, centralised so the engine and seeder agree.
     */
    public const SILKSCREEN_FIRST_COLOR = 'silkscreen_first_color_price';
    public const SILKSCREEN_ADDITIONAL_COLOR = 'silkscreen_additional_color_price';
    public const SILKSCREEN_FIRST_COLOR_FULL = 'silkscreen_first_color_full_price';
    public const SILKSCREEN_ADDITIONAL_COLOR_FULL = 'silkscreen_additional_color_full_price';
    public const DTF_PRICE_PER_SQUARE_INCH = 'dtf_price_per_square_inch';
    public const EMBROIDERY_SMALL_PRICE = 'embroidery_small_price';
    public const SUBLIMATION_JERSEY_FULL_PRICE = 'sublimation_jersey_full_price';
    public const SUBLIMATION_MESH_SHORTS_FULL_PRICE = 'sublimation_mesh_shorts_full_price';
    public const CUSTOM_PATTERN_FEE = 'custom_pattern_fee';
    public const HOODIE_ZIPPER_ADDON = 'hoodie_zipper_addon_price';
    public const HOODIE_ADDITIONAL_POCKET_ADDON = 'hoodie_additional_pocket_addon_price';
    public const HOODIE_STRINGS_ADDON = 'hoodie_strings_addon_price';
    public const DOWNPAYMENT_DEFAULT_PERCENT = 'downpayment_default_percent';
    public const DOWNPAYMENT_MINIMUM_PERCENT = 'downpayment_minimum_percent';

    /**
     * Fetch a single rate by key as a float, falling back to $default when
     * the row does not exist. Cached per-request to avoid repeat queries
     * while computing one quotation.
     */
    public static function rate(string $key, float $default = 0.0): float
    {
        static $cache = [];

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $row = static::query()->where('key', $key)->first();
        $value = $row ? (float) $row->value : $default;

        return $cache[$key] = $value;
    }
}