<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize apparel_pattern_prices.size_prices keys to the canonical short
 * labels used by the grid editor (XS, S, M, L, XL, 2XL, 3XL, 4XL) and backfill
 * a missing XS entry (set to the S value) so XS stops falling back to the flat
 * `price`.
 *
 * Why: the live grids were stored with mixed spellings ("Small"/"Medium"/
 * "Large" alongside "XL"/"2XL"/"3XL") and no XS key. priceForSize() bridged
 * S<->Small at price time, so pricing limped along, but (a) the grid editor
 * (DEFAULT_SIZE_KEYS = XS/S/M/L/...) could not round-trip those rows, and (b)
 * XS had no entry and resolved to the flat price — which on the cheaper grids
 * is HIGHER than Small, making XS the most expensive size.
 *
 * Idempotent: already-canonical keys map to themselves, and XS is only added
 * when absent. Flat-only patterns (NULL / empty size_prices) are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('apparel_pattern_prices')->get(['id', 'size_prices']);

        foreach ($rows as $row) {
            if ($row->size_prices === null || $row->size_prices === '') {
                continue;
            }

            $map = json_decode($row->size_prices, true);
            if (! is_array($map) || $map === []) {
                continue;
            }

            $normalized = [];
            foreach ($map as $key => $value) {
                $token = $this->canonicalSizeLabel($key);
                if ($token === null) {
                    continue;
                }
                // Last non-null wins if two spellings collapse to one token.
                if (! array_key_exists($token, $normalized) || $value !== null) {
                    $normalized[$token] = $value;
                }
            }

            // XS prices like the smallest size: backfill from S when missing.
            if (! array_key_exists('XS', $normalized) && array_key_exists('S', $normalized)) {
                $normalized['XS'] = $normalized['S'];
            }

            // Stable, human ordering.
            $order = ['XS' => 0, 'S' => 1, 'M' => 2, 'L' => 3, 'XL' => 4, '2XL' => 5, '3XL' => 6, '4XL' => 7];
            uksort($normalized, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

            DB::table('apparel_pattern_prices')
                ->where('id', $row->id)
                ->update(['size_prices' => json_encode($normalized)]);
        }
    }

    /**
     * Non-destructive: the original spellings are not retained, so there is
     * nothing safe to revert to. No-op (the normalized data still prices
     * correctly).
     */
    public function down(): void
    {
        // intentionally empty
    }

    /**
     * Mirror of ApparelPatternPrice::canonicalSizeToken(), but returns the
     * UPPERCASE label the grid editor uses as its key.
     */
    private function canonicalSizeLabel(?string $size): ?string
    {
        $s = strtolower(trim((string) $size));
        if ($s === '') {
            return null;
        }
        $compact = preg_replace('/[\s\-]+/', '', $s);

        return match ($compact) {
            'xs', 'extrasmall' => 'XS',
            's', 'small' => 'S',
            'm', 'medium', 'med' => 'M',
            'l', 'large' => 'L',
            'xl', 'extralarge' => 'XL',
            '2xl', 'xxl', '2x', 'xxlarge', 'doublexl', 'extraextralarge' => '2XL',
            '3xl', 'xxxl', '3x', 'xxxlarge', 'triplexl' => '3XL',
            '4xl', 'xxxxl', '4x' => '4XL',
            default => strtoupper($compact),
        };
    }
};
