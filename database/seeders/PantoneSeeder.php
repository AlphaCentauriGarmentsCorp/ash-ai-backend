<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Pantone;

/**
 * PantoneSeeder — rewritten for Phase 6-B.
 *
 * Source of truth: Sorbetes Fabric Catalog Vol 01 (2026).
 * The catalog is the authoritative reference; this seeder replaces the
 * older version that had duplicate rows from the Hoodie + 220-240 GSM
 * sections appearing twice.
 *
 * Dedupe rule: each pantone is unique by the composite key
 * (pantone_code, hexcolor). The same code can appear with different
 * hex values across collections (e.g. Black 6 C is #101820 in the
 * Hoodie Collection but #060606 in Earth Tones / Brights) — both
 * are kept because they're physically different fabric dyes that
 * just happen to map to the same Pantone reference code.
 *
 * Total: 156 unique pantones across 9 collections.
 *
 * IDEMPOTENCY: uses firstOrCreate on the composite (pantone_code,
 * hexcolor) so re-running the seeder is safe — no duplicate rows
 * will be created.
 *
 * Comments next to each row note which collection FIRST introduced
 * the pantone. A pantone may be referenced by swatches in MULTIPLE
 * collections (the FabricSwatchSeeder handles that joinery).
 */
class PantoneSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->pantones() as $data) {
                Pantone::firstOrCreate(
                    [
                        'pantone_code' => $data['pantone_code'],
                        'hexcolor'     => $data['hexcolor'],
                    ],
                    ['name' => $data['name']],
                );
            }
        });
    }

    /**
     * The full 156-pantone catalog. Grouped by first-seen collection
     * for readability — the order within the array is the order rows
     * appear in the catalog PDF.
     *
     * @return array<int, array{name: string, hexcolor: string, pantone_code: string}>
     */
    protected function pantones(): array
    {
        return [
            // ── Hoodie Collection (14 new) ──────────────
            ['name' => 'Navy', 'hexcolor' => '#131E29', 'pantone_code' => '289 C'],
            ['name' => 'Royal Navy', 'hexcolor' => '#03257E', 'pantone_code' => '3591 C'],
            ['name' => 'Cream', 'hexcolor' => '#EFDBB2', 'pantone_code' => '7506 C'],
            ['name' => 'Stone', 'hexcolor' => '#C8C3B3', 'pantone_code' => '4239 C'],
            ['name' => 'Heather Gray', 'hexcolor' => '#8C8985', 'pantone_code' => '2332 C'],
            ['name' => 'Light Gray', 'hexcolor' => '#D0C4C5', 'pantone_code' => '434 C'],
            ['name' => 'Forest Green', 'hexcolor' => '#304F42', 'pantone_code' => '4210 C'],
            ['name' => 'Olive', 'hexcolor' => '#6C5D34', 'pantone_code' => '7561 C'],
            ['name' => 'Plum', 'hexcolor' => '#5E366E', 'pantone_code' => '7665 C'],
            ['name' => 'Lavender', 'hexcolor' => '#AF95D3', 'pantone_code' => '2073 C'],
            ['name' => 'Tan', 'hexcolor' => '#A58877', 'pantone_code' => '2471 C'],
            ['name' => 'Crimson', 'hexcolor' => '#C5003E', 'pantone_code' => '1935 C'],
            ['name' => 'Black', 'hexcolor' => '#101820', 'pantone_code' => 'Black 6 C'],
            ['name' => 'Pearl Gray', 'hexcolor' => '#D8D7DF', 'pantone_code' => '5315 C'],

            // ── 280 GSM (19 new) ──────────────
            ['name' => 'Cream', 'hexcolor' => '#F2F0A1', 'pantone_code' => 'Yellow 0131 C'],
            ['name' => 'Mocha', 'hexcolor' => '#BAA58D', 'pantone_code' => '4253 C'],
            ['name' => 'Mustard', 'hexcolor' => '#9F7D23', 'pantone_code' => '7557 C'],
            ['name' => 'Brown', 'hexcolor' => '#4F2C1D', 'pantone_code' => '4625 C'],
            ['name' => 'Rust', 'hexcolor' => '#864A33', 'pantone_code' => '7581 C'],
            ['name' => 'Red', 'hexcolor' => '#7C2529', 'pantone_code' => '1815 C'],
            ['name' => 'Bright Red', 'hexcolor' => '#BA0020', 'pantone_code' => '3517 C'],
            ['name' => 'Lt. Grey', 'hexcolor' => '#A2AAAD', 'pantone_code' => '429 C'],
            ['name' => 'Dk. Gray', 'hexcolor' => '#333F48', 'pantone_code' => '432 C'],
            ['name' => 'Royal Blue', 'hexcolor' => '#00A88E', 'pantone_code' => '3581 C'],
            ['name' => 'China Blue', 'hexcolor' => '#6A6A8E', 'pantone_code' => '4141 C'],
            ['name' => 'Powder Blue', 'hexcolor' => '#B9D9EB', 'pantone_code' => '290 C'],
            ['name' => 'Dk. Yellow', 'hexcolor' => '#D69A2D', 'pantone_code' => '7563 C'],
            ['name' => 'Lt. Yellow', 'hexcolor' => '#F1B434', 'pantone_code' => '143 C'],
            ['name' => 'Mint Blue', 'hexcolor' => '#99D9EA', 'pantone_code' => '630 C'],
            ['name' => 'Dark Green', 'hexcolor' => '#183028', 'pantone_code' => '5535 C'],
            ['name' => 'Fatigue', 'hexcolor' => '#3E4827', 'pantone_code' => '5743 C'],
            ['name' => 'Peach', 'hexcolor' => '#F4C1C4', 'pantone_code' => '692 C'],
            ['name' => 'Violet', 'hexcolor' => '#662483', 'pantone_code' => '2607 C'],

            // ── 220-240 GSM Greens & Blues (18 new) ──────────────
            ['name' => 'Avocado Green', 'hexcolor' => '#66BB44', 'pantone_code' => '369 C'],
            ['name' => 'Lt. Apple Green', 'hexcolor' => '#8EDD65', 'pantone_code' => '2292 C'],
            ['name' => 'Christmas Green', 'hexcolor' => '#006B38', 'pantone_code' => '7736 C'],
            ['name' => 'Lt. Fatigue', 'hexcolor' => '#006B4F', 'pantone_code' => '5753 C'],
            ['name' => 'Milo Green', 'hexcolor' => '#05A31D', 'pantone_code' => '3529 C'],
            ['name' => 'Fatigue', 'hexcolor' => '#4E4934', 'pantone_code' => '7771 C'],
            ['name' => 'Emerald Green', 'hexcolor' => '#007A53', 'pantone_code' => '341 C'],
            ['name' => 'Military Fatigue', 'hexcolor' => '#6D654F', 'pantone_code' => '4227 C'],
            ['name' => 'Aqua Green', 'hexcolor' => '#00A9C9', 'pantone_code' => '3115 C'],
            ['name' => 'Apple Green', 'hexcolor' => '#7CCC6E', 'pantone_code' => '2269 C'],
            ['name' => 'Blue Green', 'hexcolor' => '#5998C4', 'pantone_code' => '2170 C'],
            ['name' => 'Jade Green', 'hexcolor' => '#00A3B8', 'pantone_code' => '322 C'],
            ['name' => 'China Blue', 'hexcolor' => '#5C88DA', 'pantone_code' => '2718 C'],
            ['name' => 'Lt. Aqua Blue #3', 'hexcolor' => '#005698', 'pantone_code' => '2185 C'],
            ['name' => 'Dk. China Blue', 'hexcolor' => '#3B3FB6', 'pantone_code' => '2369 C'],
            ['name' => 'Lt. Royal Blue', 'hexcolor' => '#001A70', 'pantone_code' => '662 C'],
            ['name' => 'Dk. Aqua Blue', 'hexcolor' => '#006298', 'pantone_code' => '2186 C'],
            ['name' => 'Dk. Royal Blue', 'hexcolor' => '#041E42', 'pantone_code' => '282 C'],

            // ── 220-240 GSM Neutrals (18 new) ──────────────
            ['name' => 'Peacock Blue', 'hexcolor' => '#326295', 'pantone_code' => '653 C'],
            ['name' => 'Navy Blue', 'hexcolor' => '#1B1C34', 'pantone_code' => '4146 C'],
            ['name' => 'Blue Violet', 'hexcolor' => '#8BB8E8', 'pantone_code' => '278 C'],
            ['name' => 'Lavender', 'hexcolor' => '#A68ACA', 'pantone_code' => '2086 C'],
            ['name' => 'Violet', 'hexcolor' => '#2E1A47', 'pantone_code' => '2695 C'],
            ['name' => 'Burgundy', 'hexcolor' => '#5D2A2C', 'pantone_code' => '490 C'],
            ['name' => 'Khaki Brown', 'hexcolor' => '#8C857B', 'pantone_code' => '403 C'],
            ['name' => 'Choco Brown', 'hexcolor' => '#623412', 'pantone_code' => '732 C'],
            ['name' => 'Khaki', 'hexcolor' => '#96856E', 'pantone_code' => '4270 C'],
            ['name' => 'Special Gray', 'hexcolor' => '#B2AAAC', 'pantone_code' => '4282 C'],
            ['name' => 'Gray #56', 'hexcolor' => '#403A60', 'pantone_code' => '4265 C'],
            ['name' => 'Brown', 'hexcolor' => '#7B4931', 'pantone_code' => '7602 C'],
            ['name' => 'Medium Gray', 'hexcolor' => '#788FA4', 'pantone_code' => '2164 C'],
            ['name' => 'Charcoal Gray', 'hexcolor' => '#5B618F', 'pantone_code' => '2110 C'],
            ['name' => 'Acid Gray', 'hexcolor' => '#C6C4D2', 'pantone_code' => '5305 C'],
            ['name' => 'Acid Black', 'hexcolor' => '#53565A', 'pantone_code' => 'Cool Gray 11 C'],
            ['name' => 'Medium Blue', 'hexcolor' => '#489FDF', 'pantone_code' => '2171 C'],
            ['name' => 'Black', 'hexcolor' => '#212721', 'pantone_code' => 'Black 3 C'],

            // ── 220-240 GSM Warm Tones (24 new) ──────────────
            ['name' => 'Maroon', 'hexcolor' => '#6F263D', 'pantone_code' => '209 C'],
            ['name' => 'Coke Red', 'hexcolor' => '#A50034', 'pantone_code' => '207 C'],
            ['name' => 'Top Dye', 'hexcolor' => '#B3B0C4', 'pantone_code' => '5295 C'],
            ['name' => 'Red Orange', 'hexcolor' => '#BA0C2F', 'pantone_code' => '200 C'],
            ['name' => 'Fuchsia', 'hexcolor' => '#AC145A', 'pantone_code' => '215 C'],
            ['name' => 'Ash Gray', 'hexcolor' => '#484A5B', 'pantone_code' => '4131 C'],
            ['name' => 'Fuchsia Pink', 'hexcolor' => '#DA1884', 'pantone_code' => '219 C'],
            ['name' => 'Old Rose', 'hexcolor' => '#D1889A', 'pantone_code' => '4071 C'],
            ['name' => 'Lt. Old Rose', 'hexcolor' => '#D08689', 'pantone_code' => '2446 C'],
            ['name' => 'Rust Lt.', 'hexcolor' => '#FF5C36', 'pantone_code' => '2436 C'],
            ['name' => 'Melon Peach', 'hexcolor' => '#FF8DA1', 'pantone_code' => '1775 C'],
            ['name' => 'Ponkana', 'hexcolor' => '#F4633A', 'pantone_code' => '2026 C'],
            ['name' => 'Tangerine', 'hexcolor' => '#F32301', 'pantone_code' => '2028 C'],
            ['name' => 'Dk. Mustard', 'hexcolor' => '#835D32', 'pantone_code' => '7575 C'],
            ['name' => 'Rust', 'hexcolor' => '#E35F50', 'pantone_code' => '2448 C'],
            ['name' => 'Carrot Orange', 'hexcolor' => '#F87C56', 'pantone_code' => '2024 C'],
            ['name' => 'Yellow Gold', 'hexcolor' => '#E78D2D', 'pantone_code' => '3588 C'],
            ['name' => 'Feu Gold', 'hexcolor' => '#F8B700', 'pantone_code' => '3514 C'],
            ['name' => 'Mustard', 'hexcolor' => '#C16C18', 'pantone_code' => '7414 C'],
            ['name' => 'Egg Yellow', 'hexcolor' => '#F1C400', 'pantone_code' => '7406 C'],
            ['name' => 'Luminous Green', 'hexcolor' => '#9BE198', 'pantone_code' => '2267 C'],
            ['name' => 'Canary Yellow', 'hexcolor' => '#F7EA48', 'pantone_code' => '101 C'],
            ['name' => 'Neon Green', 'hexcolor' => '#A4D233', 'pantone_code' => '2299 C'],
            ['name' => 'Grass Green', 'hexcolor' => '#1B806D', 'pantone_code' => '2244 C'],

            // ── 220-240 GSM Lights (17 new) ──────────────
            ['name' => 'White', 'hexcolor' => '#FFFFFF', 'pantone_code' => '11-4001 TPG'],
            ['name' => 'Regent Yellow', 'hexcolor' => '#F6EB61', 'pantone_code' => '604 C'],
            ['name' => 'Ivory', 'hexcolor' => '#EAE9EE', 'pantone_code' => '7499 C'],
            ['name' => 'Corn Yellow', 'hexcolor' => '#D6DCE5', 'pantone_code' => '2002 C'],
            ['name' => 'Lemon Yellow', 'hexcolor' => '#D5FFA4', 'pantone_code' => '372 C'],
            ['name' => 'Off White', 'hexcolor' => '#FCFCFC', 'pantone_code' => '663 C'],
            ['name' => 'Light Yellow', 'hexcolor' => '#F3E900', 'pantone_code' => '3945 C'],
            ['name' => 'Cream', 'hexcolor' => '#FFF8E5', 'pantone_code' => '11-0104 TPG'],
            ['name' => 'Lime Green', 'hexcolor' => '#93F9C2', 'pantone_code' => '2253 C'],
            ['name' => 'Lt. Mint Green', 'hexcolor' => '#D1FAFA', 'pantone_code' => '317 C'],
            ['name' => 'Source Green', 'hexcolor' => '#BAD2BA', 'pantone_code' => '5595 C'],
            ['name' => 'Mint Green', 'hexcolor' => '#9EE3D8', 'pantone_code' => '324 C'],
            ['name' => 'Pink', 'hexcolor' => '#F19EC2', 'pantone_code' => '236 C'],
            ['name' => 'Sea Green', 'hexcolor' => '#59ACC1', 'pantone_code' => '2226 C'],
            ['name' => 'Mocca', 'hexcolor' => '#C8A696', 'pantone_code' => '480 C'],
            ['name' => 'Sky Blue', 'hexcolor' => '#829AC4', 'pantone_code' => '2141 C'],
            ['name' => 'Powder Mint', 'hexcolor' => '#D4F8FF', 'pantone_code' => '290 C'],

            // ── 220-240 GSM Pastels (17 new) ──────────────
            ['name' => 'Silver Gray', 'hexcolor' => '#CECFD0', 'pantone_code' => 'Cool Gray 2 C'],
            ['name' => 'Canvas', 'hexcolor' => '#F2EDD7', 'pantone_code' => '7527 C'],
            ['name' => 'Lilac', 'hexcolor' => '#FCCDFB', 'pantone_code' => '2365 C'],
            ['name' => 'Dk. Peach', 'hexcolor' => '#FFB7CD', 'pantone_code' => '190 C'],
            ['name' => 'Beige', 'hexcolor' => '#E9D2B5', 'pantone_code' => '7528 C'],
            ['name' => 'Peach', 'hexcolor' => '#FFC7C2', 'pantone_code' => '706 C'],
            ['name' => 'Lt. Peach', 'hexcolor' => '#FFDDE2', 'pantone_code' => '698 C'],
            ['name' => 'Melon', 'hexcolor' => '#FF9BBF', 'pantone_code' => '1915 C'],
            ['name' => 'Baby Pink', 'hexcolor' => '#FFDEE7', 'pantone_code' => '705 C'],
            ['name' => 'Dk. Pink', 'hexcolor' => '#FF94AE', 'pantone_code' => '190 C'],
            ['name' => 'Powder Pink', 'hexcolor' => '#FFEFA5', 'pantone_code' => '691 C'],
            ['name' => 'Cannon Sunset', 'hexcolor' => '#FFA38B', 'pantone_code' => '1625 C'],
            ['name' => 'Lt. Aqua Blue', 'hexcolor' => '#67C9F5', 'pantone_code' => '298 C'],
            ['name' => 'Lt. Khaki', 'hexcolor' => '#C6BDA1', 'pantone_code' => '7535 C'],
            ['name' => 'Powder Blue', 'hexcolor' => '#A7D2EE', 'pantone_code' => '291 C'],
            ['name' => 'Misty', 'hexcolor' => '#D7BEAF', 'pantone_code' => '4755 C'],
            ['name' => 'Medium Pink', 'hexcolor' => '#FFAFCC', 'pantone_code' => '671 C'],

            // ── 220-240 GSM Earth Tones (15 new) ──────────────
            ['name' => 'Jet Black', 'hexcolor' => '#060606', 'pantone_code' => 'Black 6 C'],
            ['name' => 'Cloudy White', 'hexcolor' => '#FFFFFF', 'pantone_code' => 'P 179-1 C'],
            ['name' => 'Choco Brown', 'hexcolor' => '#83694E', 'pantone_code' => '2318 C'],
            ['name' => 'Lt. Khaki', 'hexcolor' => '#D3BA86', 'pantone_code' => '466 C'],
            ['name' => 'Mocha Mousse', 'hexcolor' => '#8F5F10', 'pantone_code' => '126 C'],
            ['name' => 'Lime Stone', 'hexcolor' => '#5D9644', 'pantone_code' => '362 C'],
            ['name' => 'Khaki', 'hexcolor' => '#BD9D59', 'pantone_code' => '465 C'],
            ['name' => 'Soft Cream', 'hexcolor' => '#FFEFC1', 'pantone_code' => '7401 C'],
            ['name' => 'Army Green', 'hexcolor' => '#549438', 'pantone_code' => '363 C'],
            ['name' => 'Mocha', 'hexcolor' => '#9F691D', 'pantone_code' => '1255 C'],
            ['name' => 'Pink', 'hexcolor' => '#FF5DA7', 'pantone_code' => '212 C'],
            ['name' => 'Choco Brown', 'hexcolor' => '#6D4837', 'pantone_code' => '4695 C'],
            ['name' => 'Old Rose', 'hexcolor' => '#FFB3AC', 'pantone_code' => '169 C'],
            ['name' => 'Cream', 'hexcolor' => '#F9DF94', 'pantone_code' => '121 C'],
            ['name' => 'Dk. Ash Gray', 'hexcolor' => '#505059', 'pantone_code' => '446 C'],

            // ── 220-240 GSM Brights (14 new) ──────────────
            ['name' => 'Canary Yellow', 'hexcolor' => '#FEEB1C', 'pantone_code' => 'Yellow C'],
            ['name' => 'Lavender', 'hexcolor' => '#9C6EC2', 'pantone_code' => '2583 C'],
            ['name' => 'Maroon', 'hexcolor' => '#982525', 'pantone_code' => '187 C'],
            ['name' => 'Royal Blue', 'hexcolor' => '#0000CE', 'pantone_code' => 'Blue 072 C'],
            ['name' => 'Gray', 'hexcolor' => '#8C8C8C', 'pantone_code' => 'Cool Gray 7 C'],
            ['name' => 'Yellow Gold', 'hexcolor' => '#FFBF00', 'pantone_code' => '116 C'],
            ['name' => 'Dk. Violet', 'hexcolor' => '#5C2080', 'pantone_code' => '2613 C'],
            ['name' => 'Dk. Bloody Red', 'hexcolor' => '#D70000', 'pantone_code' => '485 C'],
            ['name' => 'Navy Blue', 'hexcolor' => '#1B2050', 'pantone_code' => '2767 C'],
            ['name' => 'Dk. Choco Brown', 'hexcolor' => '#432700', 'pantone_code' => '4625 C'],
            ['name' => 'Rust Brown', 'hexcolor' => '#91210C', 'pantone_code' => '1807 C'],
            ['name' => 'Mustard', 'hexcolor' => '#FFB500', 'pantone_code' => '1235 C'],
            ['name' => 'Neon Green', 'hexcolor' => '#44FF44', 'pantone_code' => '802 C'],
            ['name' => 'Mint Green', 'hexcolor' => '#A8DA92', 'pantone_code' => '2253 C'],
        ];
    }
}
