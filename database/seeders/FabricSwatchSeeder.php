<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\FabricSwatch;
use App\Models\Pantone;

/**
 * FabricSwatchSeeder — Phase 6-B.
 *
 * Source of truth: Sorbetes Fabric Catalog Vol 01 (2026).
 * Seeds all 162 swatches across 9 collections:
 *
 *   01. Hoodie Collection              (14 swatches, Cotton Fleece, 320 GSM)
 *   02. 280 GSM                        (19 swatches, CVC, 280 GSM)
 *   03. 220-240 GSM Greens & Blues     (19 swatches, CVC, 240 GSM)
 *   04. 220-240 GSM Neutrals           (19 swatches, CVC, 240 GSM)
 *   05. 220-240 GSM Warm Tones         (24 swatches, CVC, 240 GSM)
 *   06. 220-240 GSM Lights             (17 swatches, CVC, 240 GSM)
 *   07. 220-240 GSM Pastels            (17 swatches, CVC, 240 GSM)
 *   08. 220-240 GSM Earth Tones        (15 swatches, CVC, 240 GSM)
 *   09. 220-240 GSM Brights            (18 swatches, CVC, 240 GSM)
 *
 * Pantone linkage: each swatch row carries a `pantone_code` + `hex_color`
 * pair, which is the composite key for lookup in the pantones table.
 * Same pantone_code with different hex = different pantone row =
 * different pantone_id. If no matching pantone row is found, pantone_id
 * is left null (defensive — should not happen if PantoneSeeder runs first).
 *
 * MUST RUN AFTER PantoneSeeder. Add this to DatabaseSeeder.php in the
 * correct order:
 *
 *   $this->call([
 *       PantoneSeeder::class,        // first
 *       FabricSwatchSeeder::class,   // then this
 *       // ...
 *   ]);
 *
 * IDEMPOTENCY: uses updateOrCreate keyed on (name, collection, hex_color)
 * — re-running won't create duplicates, and will refresh any other
 * columns (fabric_type, gsm, color_family) if the catalog changes.
 *
 * supplier_id / material_id / photo_path: all null on seed. These get
 * filled in later through the catalog admin UI as physical suppliers
 * are assigned and photos uploaded.
 */
class FabricSwatchSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // Pre-load pantones keyed by (pantone_code, hex_color) so
            // we don't run 162 individual lookup queries.
            $pantoneLookup = Pantone::query()
                ->get(['id', 'pantone_code', 'hexcolor'])
                ->keyBy(fn ($p) => $p->pantone_code . '|' . $p->hexcolor);

            foreach ($this->swatches() as $data) {
                $key     = $data['pantone_code'] . '|' . $data['hex_color'];
                $pantone = $pantoneLookup->get($key);

                FabricSwatch::updateOrCreate(
                    [
                        'name'       => $data['name'],
                        'collection' => $data['collection'],
                        'hex_color'  => $data['hex_color'],
                    ],
                    [
                        'pantone_id'   => $pantone?->id,
                        'fabric_type'  => $data['fabric_type'],
                        'gsm'          => $data['gsm'],
                        'color_family' => $data['color_family'],
                        // photo_path, supplier_id, material_id deliberately
                        // left untouched — those are managed via the UI
                        // after seed, not re-set on every run.
                    ],
                );
            }
        });
    }

    /**
     * All 162 swatches from the catalog, in catalog order.
     *
     * @return array<int, array{
     *     name: string,
     *     pantone_code: string,
     *     hex_color: string,
     *     fabric_type: string,
     *     gsm: int,
     *     collection: string,
     *     color_family: string|null
     * }>
     */
    protected function swatches(): array
    {
        return [

            // ── Hoodie Collection (14 swatches) ──────────────
            ['name' => 'Navy', 'pantone_code' => '289 C', 'hex_color' => '#131E29', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Blue'],
            ['name' => 'Royal Navy', 'pantone_code' => '3591 C', 'hex_color' => '#03257E', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Blue'],
            ['name' => 'Cream', 'pantone_code' => '7506 C', 'hex_color' => '#EFDBB2', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'White'],
            ['name' => 'Stone', 'pantone_code' => '4239 C', 'hex_color' => '#C8C3B3', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Gray'],
            ['name' => 'Heather Gray', 'pantone_code' => '2332 C', 'hex_color' => '#8C8985', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Gray'],
            ['name' => 'Light Gray', 'pantone_code' => '434 C', 'hex_color' => '#D0C4C5', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Gray'],
            ['name' => 'Forest Green', 'pantone_code' => '4210 C', 'hex_color' => '#304F42', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Green'],
            ['name' => 'Olive', 'pantone_code' => '7561 C', 'hex_color' => '#6C5D34', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Green'],
            ['name' => 'Plum', 'pantone_code' => '7665 C', 'hex_color' => '#5E366E', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Purple'],
            ['name' => 'Lavender', 'pantone_code' => '2073 C', 'hex_color' => '#AF95D3', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Purple'],
            ['name' => 'Tan', 'pantone_code' => '2471 C', 'hex_color' => '#A58877', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Brown'],
            ['name' => 'Crimson', 'pantone_code' => '1935 C', 'hex_color' => '#C5003E', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Red'],
            ['name' => 'Black', 'pantone_code' => 'Black 6 C', 'hex_color' => '#101820', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Black'],
            ['name' => 'Pearl Gray', 'pantone_code' => '5315 C', 'hex_color' => '#D8D7DF', 'fabric_type' => 'Cotton Fleece', 'gsm' => 320, 'collection' => 'Hoodie Collection', 'color_family' => 'Gray'],

            // ── 280 GSM (19 swatches) ──────────────
            ['name' => 'Cream', 'pantone_code' => 'Yellow 0131 C', 'hex_color' => '#F2F0A1', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'White'],
            ['name' => 'Mocha', 'pantone_code' => '4253 C', 'hex_color' => '#BAA58D', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Brown'],
            ['name' => 'Mustard', 'pantone_code' => '7557 C', 'hex_color' => '#9F7D23', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Yellow'],
            ['name' => 'Brown', 'pantone_code' => '4625 C', 'hex_color' => '#4F2C1D', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Brown'],
            ['name' => 'Rust', 'pantone_code' => '7581 C', 'hex_color' => '#864A33', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Orange'],
            ['name' => 'Red', 'pantone_code' => '1815 C', 'hex_color' => '#7C2529', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Red'],
            ['name' => 'Bright Red', 'pantone_code' => '3517 C', 'hex_color' => '#BA0020', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Red'],
            ['name' => 'Lt. Grey', 'pantone_code' => '429 C', 'hex_color' => '#A2AAAD', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Gray'],
            ['name' => 'Dk. Gray', 'pantone_code' => '432 C', 'hex_color' => '#333F48', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Gray'],
            ['name' => 'Royal Blue', 'pantone_code' => '3581 C', 'hex_color' => '#00A88E', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Blue'],
            ['name' => 'China Blue', 'pantone_code' => '4141 C', 'hex_color' => '#6A6A8E', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Blue'],
            ['name' => 'Powder Blue', 'pantone_code' => '290 C', 'hex_color' => '#B9D9EB', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Blue'],
            ['name' => 'Dk. Yellow', 'pantone_code' => '7563 C', 'hex_color' => '#D69A2D', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Yellow'],
            ['name' => 'Lt. Yellow', 'pantone_code' => '143 C', 'hex_color' => '#F1B434', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Yellow'],
            ['name' => 'Mint Blue', 'pantone_code' => '630 C', 'hex_color' => '#99D9EA', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Blue'],
            ['name' => 'Dark Green', 'pantone_code' => '5535 C', 'hex_color' => '#183028', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Green'],
            ['name' => 'Fatigue', 'pantone_code' => '5743 C', 'hex_color' => '#3E4827', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Green'],
            ['name' => 'Peach', 'pantone_code' => '692 C', 'hex_color' => '#F4C1C4', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Pink'],
            ['name' => 'Violet', 'pantone_code' => '2607 C', 'hex_color' => '#662483', 'fabric_type' => 'CVC', 'gsm' => 280, 'collection' => '280 GSM', 'color_family' => 'Purple'],

            // ── 220-240 GSM Greens & Blues (19 swatches) ──────────────
            ['name' => 'Avocado Green', 'pantone_code' => '369 C', 'hex_color' => '#66BB44', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Bottle Green', 'pantone_code' => '5535 C', 'hex_color' => '#183028', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Lt. Apple Green', 'pantone_code' => '2292 C', 'hex_color' => '#8EDD65', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Christmas Green', 'pantone_code' => '7736 C', 'hex_color' => '#006B38', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Lt. Fatigue', 'pantone_code' => '5753 C', 'hex_color' => '#006B4F', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Milo Green', 'pantone_code' => '3529 C', 'hex_color' => '#05A31D', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Fatigue', 'pantone_code' => '7771 C', 'hex_color' => '#4E4934', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Emerald Green', 'pantone_code' => '341 C', 'hex_color' => '#007A53', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Military Fatigue', 'pantone_code' => '4227 C', 'hex_color' => '#6D654F', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Aqua Green', 'pantone_code' => '3115 C', 'hex_color' => '#00A9C9', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Apple Green', 'pantone_code' => '2269 C', 'hex_color' => '#7CCC6E', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Blue Green', 'pantone_code' => '2170 C', 'hex_color' => '#5998C4', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'Jade Green', 'pantone_code' => '322 C', 'hex_color' => '#00A3B8', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Green'],
            ['name' => 'China Blue', 'pantone_code' => '2718 C', 'hex_color' => '#5C88DA', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Blue'],
            ['name' => 'Lt. Aqua Blue #3', 'pantone_code' => '2185 C', 'hex_color' => '#005698', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Blue'],
            ['name' => 'Dk. China Blue', 'pantone_code' => '2369 C', 'hex_color' => '#3B3FB6', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Blue'],
            ['name' => 'Lt. Royal Blue', 'pantone_code' => '662 C', 'hex_color' => '#001A70', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Blue'],
            ['name' => 'Dk. Aqua Blue', 'pantone_code' => '2186 C', 'hex_color' => '#006298', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Blue'],
            ['name' => 'Dk. Royal Blue', 'pantone_code' => '282 C', 'hex_color' => '#041E42', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Greens & Blues', 'color_family' => 'Blue'],

            // ── 220-240 GSM Neutrals (19 swatches) ──────────────
            ['name' => 'Peacock Blue', 'pantone_code' => '653 C', 'hex_color' => '#326295', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Blue'],
            ['name' => 'Navy Blue', 'pantone_code' => '4146 C', 'hex_color' => '#1B1C34', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Blue'],
            ['name' => 'Blue Violet', 'pantone_code' => '278 C', 'hex_color' => '#8BB8E8', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Blue'],
            ['name' => 'Lavender', 'pantone_code' => '2086 C', 'hex_color' => '#A68ACA', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Purple'],
            ['name' => 'Violet', 'pantone_code' => '2695 C', 'hex_color' => '#2E1A47', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Purple'],
            ['name' => 'Burgundy', 'pantone_code' => '490 C', 'hex_color' => '#5D2A2C', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Red'],
            ['name' => 'Khaki Brown', 'pantone_code' => '403 C', 'hex_color' => '#8C857B', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Brown'],
            ['name' => 'Choco Brown', 'pantone_code' => '732 C', 'hex_color' => '#623412', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Brown'],
            ['name' => 'Khaki', 'pantone_code' => '4270 C', 'hex_color' => '#96856E', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Brown'],
            ['name' => 'Special Gray', 'pantone_code' => '4282 C', 'hex_color' => '#B2AAAC', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Gray'],
            ['name' => 'Gray #56', 'pantone_code' => '4265 C', 'hex_color' => '#403A60', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Gray'],
            ['name' => 'Brown', 'pantone_code' => '7602 C', 'hex_color' => '#7B4931', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Brown'],
            ['name' => 'Medium Gray', 'pantone_code' => '2164 C', 'hex_color' => '#788FA4', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Gray'],
            ['name' => 'Charcoal Gray', 'pantone_code' => '2110 C', 'hex_color' => '#5B618F', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Gray'],
            ['name' => 'Acid Gray', 'pantone_code' => '5305 C', 'hex_color' => '#C6C4D2', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Gray'],
            ['name' => 'Acid Black', 'pantone_code' => 'Cool Gray 11 C', 'hex_color' => '#53565A', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Black'],
            ['name' => 'Midnight Blue', 'pantone_code' => '4146 C', 'hex_color' => '#1B1C34', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Blue'],
            ['name' => 'Medium Blue', 'pantone_code' => '2171 C', 'hex_color' => '#489FDF', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Blue'],
            ['name' => 'Black', 'pantone_code' => 'Black 3 C', 'hex_color' => '#212721', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Neutrals', 'color_family' => 'Black'],

            // ── 220-240 GSM Warm Tones (24 swatches) ──────────────
            ['name' => 'Maroon', 'pantone_code' => '209 C', 'hex_color' => '#6F263D', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Red'],
            ['name' => 'Coke Red', 'pantone_code' => '207 C', 'hex_color' => '#A50034', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Red'],
            ['name' => 'Top Dye', 'pantone_code' => '5295 C', 'hex_color' => '#B3B0C4', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Gray'],
            ['name' => 'Red Orange', 'pantone_code' => '200 C', 'hex_color' => '#BA0C2F', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Red'],
            ['name' => 'Fuchsia', 'pantone_code' => '215 C', 'hex_color' => '#AC145A', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Pink'],
            ['name' => 'Ash Gray', 'pantone_code' => '4131 C', 'hex_color' => '#484A5B', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Gray'],
            ['name' => 'Fuchsia Pink', 'pantone_code' => '219 C', 'hex_color' => '#DA1884', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Pink'],
            ['name' => 'Old Rose', 'pantone_code' => '4071 C', 'hex_color' => '#D1889A', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Pink'],
            ['name' => 'Lt. Old Rose', 'pantone_code' => '2446 C', 'hex_color' => '#D08689', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Pink'],
            ['name' => 'Rust Lt.', 'pantone_code' => '2436 C', 'hex_color' => '#FF5C36', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Orange'],
            ['name' => 'Melon Peach', 'pantone_code' => '1775 C', 'hex_color' => '#FF8DA1', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Pink'],
            ['name' => 'Ponkana', 'pantone_code' => '2026 C', 'hex_color' => '#F4633A', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Orange'],
            ['name' => 'Tangerine', 'pantone_code' => '2028 C', 'hex_color' => '#F32301', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Orange'],
            ['name' => 'Dk. Mustard', 'pantone_code' => '7575 C', 'hex_color' => '#835D32', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Yellow'],
            ['name' => 'Rust', 'pantone_code' => '2448 C', 'hex_color' => '#E35F50', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Orange'],
            ['name' => 'Carrot Orange', 'pantone_code' => '2024 C', 'hex_color' => '#F87C56', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Orange'],
            ['name' => 'Yellow Gold', 'pantone_code' => '3588 C', 'hex_color' => '#E78D2D', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Yellow'],
            ['name' => 'Feu Gold', 'pantone_code' => '3514 C', 'hex_color' => '#F8B700', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Yellow'],
            ['name' => 'Mustard', 'pantone_code' => '7414 C', 'hex_color' => '#C16C18', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Yellow'],
            ['name' => 'Egg Yellow', 'pantone_code' => '7406 C', 'hex_color' => '#F1C400', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Yellow'],
            ['name' => 'Luminous Green', 'pantone_code' => '2267 C', 'hex_color' => '#9BE198', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Green'],
            ['name' => 'Canary Yellow', 'pantone_code' => '101 C', 'hex_color' => '#F7EA48', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Yellow'],
            ['name' => 'Neon Green', 'pantone_code' => '2299 C', 'hex_color' => '#A4D233', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Green'],
            ['name' => 'Grass Green', 'pantone_code' => '2244 C', 'hex_color' => '#1B806D', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Warm Tones', 'color_family' => 'Green'],

            // ── 220-240 GSM Lights (17 swatches) ──────────────
            ['name' => 'White', 'pantone_code' => '11-4001 TPG', 'hex_color' => '#FFFFFF', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'White'],
            ['name' => 'Regent Yellow', 'pantone_code' => '604 C', 'hex_color' => '#F6EB61', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Yellow'],
            ['name' => 'Ivory', 'pantone_code' => '7499 C', 'hex_color' => '#EAE9EE', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'White'],
            ['name' => 'Corn Yellow', 'pantone_code' => '2002 C', 'hex_color' => '#D6DCE5', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Yellow'],
            ['name' => 'Lemon Yellow', 'pantone_code' => '372 C', 'hex_color' => '#D5FFA4', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Yellow'],
            ['name' => 'Off White', 'pantone_code' => '663 C', 'hex_color' => '#FCFCFC', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'White'],
            ['name' => 'Light Yellow', 'pantone_code' => '3945 C', 'hex_color' => '#F3E900', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Yellow'],
            ['name' => 'Cream', 'pantone_code' => '11-0104 TPG', 'hex_color' => '#FFF8E5', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'White'],
            ['name' => 'Lime Green', 'pantone_code' => '2253 C', 'hex_color' => '#93F9C2', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Green'],
            ['name' => 'Lt. Mint Green', 'pantone_code' => '317 C', 'hex_color' => '#D1FAFA', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Green'],
            ['name' => 'Source Green', 'pantone_code' => '5595 C', 'hex_color' => '#BAD2BA', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Green'],
            ['name' => 'Mint Green', 'pantone_code' => '324 C', 'hex_color' => '#9EE3D8', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Green'],
            ['name' => 'Pink', 'pantone_code' => '236 C', 'hex_color' => '#F19EC2', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Pink'],
            ['name' => 'Sea Green', 'pantone_code' => '2226 C', 'hex_color' => '#59ACC1', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Green'],
            ['name' => 'Mocca', 'pantone_code' => '480 C', 'hex_color' => '#C8A696', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Brown'],
            ['name' => 'Sky Blue', 'pantone_code' => '2141 C', 'hex_color' => '#829AC4', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Blue'],
            ['name' => 'Powder Mint', 'pantone_code' => '290 C', 'hex_color' => '#D4F8FF', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Lights', 'color_family' => 'Blue'],

            // ── 220-240 GSM Pastels (17 swatches) ──────────────
            ['name' => 'Silver Gray', 'pantone_code' => 'Cool Gray 2 C', 'hex_color' => '#CECFD0', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Gray'],
            ['name' => 'Canvas', 'pantone_code' => '7527 C', 'hex_color' => '#F2EDD7', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Brown'],
            ['name' => 'Lilac', 'pantone_code' => '2365 C', 'hex_color' => '#FCCDFB', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Purple'],
            ['name' => 'Dk. Peach', 'pantone_code' => '190 C', 'hex_color' => '#FFB7CD', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Pink'],
            ['name' => 'Beige', 'pantone_code' => '7528 C', 'hex_color' => '#E9D2B5', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Brown'],
            ['name' => 'Peach', 'pantone_code' => '706 C', 'hex_color' => '#FFC7C2', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Pink'],
            ['name' => 'Lt. Peach', 'pantone_code' => '698 C', 'hex_color' => '#FFDDE2', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Pink'],
            ['name' => 'Melon', 'pantone_code' => '1915 C', 'hex_color' => '#FF9BBF', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Pink'],
            ['name' => 'Baby Pink', 'pantone_code' => '705 C', 'hex_color' => '#FFDEE7', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Pink'],
            ['name' => 'Dk. Pink', 'pantone_code' => '190 C', 'hex_color' => '#FF94AE', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Pink'],
            ['name' => 'Powder Pink', 'pantone_code' => '691 C', 'hex_color' => '#FFEFA5', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Pink'],
            ['name' => 'Cannon Sunset', 'pantone_code' => '1625 C', 'hex_color' => '#FFA38B', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Orange'],
            ['name' => 'Lt. Aqua Blue', 'pantone_code' => '298 C', 'hex_color' => '#67C9F5', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Blue'],
            ['name' => 'Lt. Khaki', 'pantone_code' => '7535 C', 'hex_color' => '#C6BDA1', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Brown'],
            ['name' => 'Powder Blue', 'pantone_code' => '291 C', 'hex_color' => '#A7D2EE', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Blue'],
            ['name' => 'Misty', 'pantone_code' => '4755 C', 'hex_color' => '#D7BEAF', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Gray'],
            ['name' => 'Medium Pink', 'pantone_code' => '671 C', 'hex_color' => '#FFAFCC', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Pastels', 'color_family' => 'Pink'],

            // ── 220-240 GSM Earth Tones (15 swatches) ──────────────
            ['name' => 'Jet Black', 'pantone_code' => 'Black 6 C', 'hex_color' => '#060606', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Black'],
            ['name' => 'Cloudy White', 'pantone_code' => 'P 179-1 C', 'hex_color' => '#FFFFFF', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'White'],
            ['name' => 'Choco Brown', 'pantone_code' => '2318 C', 'hex_color' => '#83694E', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Brown'],
            ['name' => 'Lt. Khaki', 'pantone_code' => '466 C', 'hex_color' => '#D3BA86', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Brown'],
            ['name' => 'Mocha Mousse', 'pantone_code' => '126 C', 'hex_color' => '#8F5F10', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Brown'],
            ['name' => 'Lime Stone', 'pantone_code' => '362 C', 'hex_color' => '#5D9644', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Gray'],
            ['name' => 'Khaki', 'pantone_code' => '465 C', 'hex_color' => '#BD9D59', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Brown'],
            ['name' => 'Soft Cream', 'pantone_code' => '7401 C', 'hex_color' => '#FFEFC1', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'White'],
            ['name' => 'Army Green', 'pantone_code' => '363 C', 'hex_color' => '#549438', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Green'],
            ['name' => 'Mocha', 'pantone_code' => '1255 C', 'hex_color' => '#9F691D', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Brown'],
            ['name' => 'Pink', 'pantone_code' => '212 C', 'hex_color' => '#FF5DA7', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Pink'],
            ['name' => 'Choco Brown', 'pantone_code' => '4695 C', 'hex_color' => '#6D4837', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Brown'],
            ['name' => 'Old Rose', 'pantone_code' => '169 C', 'hex_color' => '#FFB3AC', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Pink'],
            ['name' => 'Cream', 'pantone_code' => '121 C', 'hex_color' => '#F9DF94', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'White'],
            ['name' => 'Dk. Ash Gray', 'pantone_code' => '446 C', 'hex_color' => '#505059', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Earth Tones', 'color_family' => 'Gray'],

            // ── 220-240 GSM Brights (18 swatches) ──────────────
            ['name' => 'Jet Black', 'pantone_code' => 'Black 6 C', 'hex_color' => '#060606', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Black'],
            ['name' => 'White', 'pantone_code' => 'P 179-1 C', 'hex_color' => '#FFFFFF', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'White'],
            ['name' => 'Canary Yellow', 'pantone_code' => 'Yellow C', 'hex_color' => '#FEEB1C', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Yellow'],
            ['name' => 'Lavender', 'pantone_code' => '2583 C', 'hex_color' => '#9C6EC2', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Purple'],
            ['name' => 'Maroon', 'pantone_code' => '187 C', 'hex_color' => '#982525', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Red'],
            ['name' => 'Royal Blue', 'pantone_code' => 'Blue 072 C', 'hex_color' => '#0000CE', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Blue'],
            ['name' => 'Khaki', 'pantone_code' => '465 C', 'hex_color' => '#BD9D59', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Brown'],
            ['name' => 'Gray', 'pantone_code' => 'Cool Gray 7 C', 'hex_color' => '#8C8C8C', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Gray'],
            ['name' => 'Yellow Gold', 'pantone_code' => '116 C', 'hex_color' => '#FFBF00', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Yellow'],
            ['name' => 'Dk. Violet', 'pantone_code' => '2613 C', 'hex_color' => '#5C2080', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Purple'],
            ['name' => 'Dk. Bloody Red', 'pantone_code' => '485 C', 'hex_color' => '#D70000', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Red'],
            ['name' => 'Navy Blue', 'pantone_code' => '2767 C', 'hex_color' => '#1B2050', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Blue'],
            ['name' => 'Dk. Choco Brown', 'pantone_code' => '4625 C', 'hex_color' => '#432700', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Brown'],
            ['name' => 'Rust Brown', 'pantone_code' => '1807 C', 'hex_color' => '#91210C', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Orange'],
            ['name' => 'Mustard', 'pantone_code' => '1235 C', 'hex_color' => '#FFB500', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Yellow'],
            ['name' => 'Army Green', 'pantone_code' => '363 C', 'hex_color' => '#549438', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Green'],
            ['name' => 'Neon Green', 'pantone_code' => '802 C', 'hex_color' => '#44FF44', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Green'],
            ['name' => 'Mint Green', 'pantone_code' => '2253 C', 'hex_color' => '#A8DA92', 'fabric_type' => 'CVC', 'gsm' => 240, 'collection' => '220-240 GSM Brights', 'color_family' => 'Green'],
        ];
    }
}
