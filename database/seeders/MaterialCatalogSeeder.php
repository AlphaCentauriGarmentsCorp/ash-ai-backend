<?php

namespace Database\Seeders;

use App\Models\Materials;
use Illuminate\Database\Seeder;

/**
 * MaterialCatalogSeeder
 *
 * Seeds the raw-materials catalog from the client's
 * "Equipment & Raw Materials Inventory" reference sheet (54 items).
 *
 * IDEMPOTENT — keyed on (name, material_type) via firstOrCreate, so it is
 * safe to run repeatedly on the live DB without creating duplicates:
 *
 *     php artisan db:seed --class=MaterialCatalogSeeder
 *
 * supplier_id is left NULL: the source sheet lists product brands
 * (Keenworth, Arbitex) rather than vendor accounts, and the materials
 * table now allows a null supplier.
 */
class MaterialCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $row) {
            Materials::firstOrCreate(
                [
                    'name'          => $row['name'],
                    'material_type' => $row['material_type'],
                ],
                [
                    'supplier_id' => null,
                    'unit'        => $row['unit'] ?? null,
                    'price'       => $row['price'] ?? null,
                    'minimum'     => $row['minimum'] ?? null,
                    'lead'        => $row['lead'] ?? null,
                    'notes'       => $row['notes'] ?? null,
                ]
            );
        }
    }

    /**
     * The 54-item catalog, grouped by material type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalog(): array
    {
        $rows = [];

        // 1) Silkscreen Paint — Keenworth (per kg) — 15 items
        $paint = [
            'Black'            => 850.00,
            'Navy Blue'        => 1898.00,
            'Royal Blue'       => 1815.00,
            'Sky Blue'         => 1830.00,
            'Brown'            => 1830.00,
            'Apple Green'      => 1320.00,
            'Emerald Green'    => 1395.00,
            'Orange'           => 1320.00,
            'Carmine Red'      => 3075.00,
            'Red Rubine'       => 1545.00,
            'Scarlet Deep'     => 1545.00,
            'Violet'           => 2295.00,
            'White'            => 990.00,
            'Yellow Lemon Top' => 1425.00,
            'Yellow Lemon Y'   => 1425.00,
        ];
        foreach ($paint as $color => $price) {
            $rows[] = [
                'name'          => "{$color} Silkscreen Paint (Keenworth)",
                'material_type' => 'Silkscreen Paint',
                'unit'          => 'per kg',
                'price'         => $price,
            ];
        }

        // 2) Polyester Threads (5000 yds/spool) — flat ₱80.00 — 17 items
        $threadColors = [
            'Black', 'Blue', 'Orange', 'Pink', 'Dark Brown', 'Fuchsia Pink',
            'Light Brown', 'Yellow Gold', 'Dark Grey', 'Violet Red', 'Dark Yellow',
            'Light Blue', 'Yellow', 'Teal', 'Green', 'Royal Blue', 'Canary Yellow',
        ];
        foreach ($threadColors as $color) {
            $rows[] = [
                'name'          => "{$color} Polyester Thread",
                'material_type' => 'Threads',
                'unit'          => 'per spool (5000 yds)',
                'price'         => 80.00,
            ];
        }

        // 3) Adhesives / Bonding Agents — 3 items
        $rows[] = [
            'name'          => 'Arbitex White',
            'material_type' => 'Adhesives / Bonding Agents',
            'unit'          => '25 kg pack',
            'price'         => 9500.00,
        ];
        $rows[] = [
            'name'          => 'Arbitex Matte',
            'material_type' => 'Adhesives / Bonding Agents',
            'unit'          => '20 kg pack',
            'price'         => 6600.00,
        ];
        $rows[] = [
            'name'          => 'Anti-Migration WB (Dark Blue)',
            'material_type' => 'Adhesives / Bonding Agents',
            'unit'          => '15 kg pack',
            'price'         => 6300.00,
        ];

        // 4) Silkscreen Paint — CMYK Waterbase (250g) — flat ₱420.00 — 4 items
        foreach (['Black', 'Yellow', 'Magenta', 'Cyan'] as $color) {
            $rows[] = [
                'name'          => "{$color} CMYK Waterbase",
                'material_type' => 'Silkscreen Paint - CMYK Waterbase',
                'unit'          => 'per 250g pack',
                'price'         => 420.00,
            ];
        }

        // 5) Fabric (per kilo) — 5 items
        $fabric = [
            'CVC Fabric'          => 321.60,
            'Twill Fabric'        => 309.60,
            'Brush Cotton Fabric' => 307.20,
            'Dri-Fit Fabric'      => 302.40,
            'TC Fabric'           => 312.00,
        ];
        foreach ($fabric as $name => $price) {
            $rows[] = [
                'name'          => $name,
                'material_type' => 'Fabric',
                'unit'          => 'per kg',
                'price'         => $price,
            ];
        }

        // 6) Ribbing (per kilo) — 4 items
        $ribbing = [
            'Cotton Ribbing'    => 330.00,
            'Polyester Ribbing' => 300.00,
            'CVC Ribbing'       => 340.00,
            'Spandex Ribbing'   => 360.00,
        ];
        foreach ($ribbing as $name => $price) {
            $rows[] = [
                'name'          => $name,
                'material_type' => 'Ribbing',
                'unit'          => 'per kg',
                'price'         => $price,
            ];
        }

        // 7) Poly Bags (per piece, sold in packs of 100) — 6 items
        $polyBags = [
            '22x38' => 14.50,
            '30x50' => 28.70,
            '25x50' => 23.60,
            '20x30' => 10.40,
            '40x40' => 29.00,
            '40x60' => 43.90,
        ];
        foreach ($polyBags as $size => $price) {
            $rows[] = [
                'name'          => "{$size} Poly Bag",
                'material_type' => 'Poly Bags',
                'unit'          => 'per piece',
                'price'         => $price,
                'notes'         => 'Sold in packs of 100',
            ];
        }

        return $rows;
    }
}
