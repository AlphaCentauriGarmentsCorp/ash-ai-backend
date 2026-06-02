<?php

namespace Database\Seeders;

use App\Models\ApparelPart;
use Illuminate\Database\Seeder;

/**
 * Seeds the standard apparel parts (print placements) used when building a
 * quotation: Front, Back, Neckline, Sleeves (left/right), and Collar.
 *
 * Idempotent — matches on `name`, so re-running updates the description
 * without creating duplicates and without clobbering parts the Superadmin
 * added later.
 */
class ApparelPartSeeder extends Seeder
{
    public function run(): void
    {
        $parts = [
            ['name' => 'Front',         'description' => 'Front print placement.'],
            ['name' => 'Back',          'description' => 'Back print placement.'],
            ['name' => 'Neckline',      'description' => 'Neckline / inner-neck placement (e.g. neck label print).'],
            ['name' => 'Sleeve - Left', 'description' => 'Left sleeve print placement (treated as a regular-size placement).'],
            ['name' => 'Sleeve - Right','description' => 'Right sleeve print placement (treated as a regular-size placement).'],
            ['name' => 'Collar',        'description' => 'Collar print placement.'],
        ];

        foreach ($parts as $part) {
            ApparelPart::updateOrCreate(
                ['name' => $part['name']],
                ['description' => $part['description']],
            );
        }
    }
}
