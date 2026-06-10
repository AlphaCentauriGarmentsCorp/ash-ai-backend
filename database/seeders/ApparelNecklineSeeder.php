<?php

namespace Database\Seeders;

use App\Models\ApparelNeckline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Apparel neckline lookup.
 *
 * Garment-colour-independent neckline charge added on top of the base
 * apparel/pattern price (see the Cost Breakdown "Neckline" column on the
 * quotation PDF). Idempotent — matched by name, so re-running updates the
 * price without creating duplicates.
 *
 * NOTE: the `apparel_necklines` table is name + price only (no notes column),
 * so the descriptive notes below live as comments. If those should be stored
 * / shown in the UI, add a `notes` (or `description`) column first.
 */
class ApparelNecklineSeeder extends Seeder
{
    public function run(): void
    {
        $necklines = [
            ['name' => 'Standard', 'price' => 0.00],   // Default
            ['name' => 'Proclub',  'price' => 20.00],  // Wider neckline, so priced higher
        ];

        foreach ($necklines as $data) {
            ApparelNeckline::updateOrCreate(
                ['name' => $data['name']],
                [
                    'price'      => $data['price'],
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
        }
    }
}
