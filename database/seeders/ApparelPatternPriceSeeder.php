<?php

namespace Database\Seeders;

use App\Models\ApparelPatternPrice;
use App\Models\ApparelType;
use App\Models\PatternType;
use Illuminate\Database\Seeder;

/**
 * Seeds per-size base prices (Blueprint Section 3.2 / Issue 6) for the
 * apparel + pattern + size combinations confirmed by the client.
 *
 * These are BASE prices (per piece) WITHOUT print. The pricing engine adds
 * print charge, neckline, and add-ons on top. Verified against the client's
 * actual Excel: e.g. Tshirt Premium Standard Small base ₱200 + silkscreen
 * (Front 1 + Back 2 = ₱140) = ₱340 price-per-piece, matching the sheet.
 *
 * Confirmed prices:
 *   Tshirt - Premium / Standard:   S/M 200, L/XL 210, 2XL/3XL 230
 *   Tshirt - Premium / Boxy:       S/M 230, L/XL 240, 2XL/3XL 260
 *   Tshirt - Premium / Oversized:  S/M 230, L/XL 240, 2XL/3XL 260
 *   Hoodie - Heavyweight / Standard: S/M 650, L/XL 680, 2XL/3XL 710
 *   Long sleeve / Standard:        S/M 450, L/XL 470, 2XL/3XL 490
 *
 * Note: Custom fit has NO seeded price — the CSR manually picks the nearest
 * existing fit for the base, and a one-time ₱500 pattern fee (a PricingSetting)
 * is added once per order by the engine.
 *
 * Sizes are manageable — Superadmin can add/remove sizes per combination in
 * the Settings grid. Idempotent: matches on (apparel_type_name,
 * pattern_type_name) and only fills size_prices if the row has none yet, so
 * re-running will NOT clobber Superadmin's later edits.
 */
class ApparelPatternPriceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'apparel' => 'Tshirt - Premium',
                'pattern' => 'Standard',
                'size_prices' => [
                    'Small' => 200, 'Medium' => 200,
                    'Large' => 210, 'XL' => 210,
                    '2XL' => 230, '3XL' => 230,
                ],
            ],
            [
                'apparel' => 'Tshirt - Premium',
                'pattern' => 'Boxy',
                'size_prices' => [
                    'Small' => 230, 'Medium' => 230,
                    'Large' => 240, 'XL' => 240,
                    '2XL' => 260, '3XL' => 260,
                ],
            ],
            [
                'apparel' => 'Tshirt - Premium',
                'pattern' => 'Oversized',
                'size_prices' => [
                    'Small' => 230, 'Medium' => 230,
                    'Large' => 240, 'XL' => 240,
                    '2XL' => 260, '3XL' => 260,
                ],
            ],
            [
                'apparel' => 'Hoodie - Heavyweight',
                'pattern' => 'Standard',
                'size_prices' => [
                    'Small' => 650, 'Medium' => 650,
                    'Large' => 680, 'XL' => 680,
                    '2XL' => 710, '3XL' => 710,
                ],
            ],
            // Non-Premium T-shirt: same prices as Premium for now, but a
            // SEPARATE row so the owner can later lower non-Premium or raise
            // Premium independently (owner request).
            [
                'apparel' => 'Tshirt - Non-Premium',
                'pattern' => 'Standard',
                'size_prices' => [
                    'Small' => 200, 'Medium' => 200,
                    'Large' => 210, 'XL' => 210,
                    '2XL' => 230, '3XL' => 230,
                ],
            ],
            [
                'apparel' => 'Tshirt - Non-Premium',
                'pattern' => 'Boxy',
                'size_prices' => [
                    'Small' => 230, 'Medium' => 230,
                    'Large' => 240, 'XL' => 240,
                    '2XL' => 260, '3XL' => 260,
                ],
            ],
            [
                'apparel' => 'Tshirt - Non-Premium',
                'pattern' => 'Oversized',
                'size_prices' => [
                    'Small' => 230, 'Medium' => 230,
                    'Large' => 240, 'XL' => 240,
                    '2XL' => 260, '3XL' => 260,
                ],
            ],
            // Regular (non-heavyweight) hoodie — REMOVED: there is no
            // "Hoodie - Regular" apparel type seeded. The owner uses a single
            // hoodie with option toggles (zipper / pockets / strings) instead.
            [
                'apparel' => 'Long sleeve',
                'pattern' => 'Standard',
                'size_prices' => [
                    'Small' => 450, 'Medium' => 450,
                    'Large' => 470, 'XL' => 470,
                    '2XL' => 490, '3XL' => 490,
                ],
            ],
            // Other T-shirt lines: seeded with the SAME prices as Premium for
            // now (owner's instruction), as separate rows so each can be
            // adjusted independently in Settings later. Standard pattern only
            // as a starting point — owner can add Boxy/Oversized in the grid.
            [
                'apparel' => 'Tshirt - Heavyweight',
                'pattern' => 'Standard',
                'size_prices' => [
                    'Small' => 200, 'Medium' => 200,
                    'Large' => 210, 'XL' => 210,
                    '2XL' => 230, '3XL' => 230,
                ],
            ],
            [
                'apparel' => 'Tshirt - Acid Wash',
                'pattern' => 'Standard',
                'size_prices' => [
                    'Small' => 200, 'Medium' => 200,
                    'Large' => 210, 'XL' => 210,
                    '2XL' => 230, '3XL' => 230,
                ],
            ],
            [
                'apparel' => 'Tshirt - Tiedye',
                'pattern' => 'Standard',
                'size_prices' => [
                    'Small' => 200, 'Medium' => 200,
                    'Large' => 210, 'XL' => 210,
                    '2XL' => 230, '3XL' => 230,
                ],
            ],
        ];

        foreach ($rows as $row) {
            $apparel = ApparelType::where('name', $row['apparel'])->first();
            $pattern = PatternType::where('name', $row['pattern'])->first();

            // Skip silently if the apparel type isn't seeded — avoids creating
            // orphan rows that the lookup could never match.
            if (! $apparel) {
                continue;
            }

            // The legacy single price = the smallest size's price, so older
            // code paths still get a sane base.
            $legacyPrice = min($row['size_prices']);

            $existing = ApparelPatternPrice::where('apparel_type_name', $row['apparel'])
                ->where('pattern_type_name', $row['pattern'])
                ->first();

            if ($existing) {
                // Only fill size_prices if not already configured.
                if (empty($existing->size_prices)) {
                    $existing->update([
                        'apparel_type_id' => $existing->apparel_type_id ?? $apparel->id,
                        'pattern_type_id' => $existing->pattern_type_id ?? $pattern?->id,
                        'size_prices' => $row['size_prices'],
                    ]);
                }
            } else {
                ApparelPatternPrice::create([
                    'apparel_type_id' => $apparel->id,
                    'pattern_type_id' => $pattern?->id,
                    'apparel_type_name' => $row['apparel'],
                    'pattern_type_name' => $row['pattern'],
                    'price' => $legacyPrice,
                    'size_prices' => $row['size_prices'],
                ]);
            }
        }
    }
}
