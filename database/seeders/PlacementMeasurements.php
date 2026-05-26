<?php

namespace Database\Seeders;

use App\Models\PlacementMeasurement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the placement_measurements dropdown (Issue 7 — label measurement).
 *
 * These are STARTER defaults so the Measurement dropdown is not empty out of
 * the box. The Superadmin can edit/add/remove them at any time via
 * Drop Down Settings → Placement Measurements (full CRUD already exists at
 * /placement-measurement). Idempotent: updateOrCreate keyed on name means
 * re-running won't duplicate rows.
 *
 * Measurement is the OPTIONAL detail on a label spec — it describes how far /
 * how big the label sits at its placement. Values are deliberately generic;
 * tune them to ACGC's actual shop standards.
 */
class PlacementMeasurements extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'name' => '1 inch',
                'description' => 'Label/print measuring approximately 1 inch — small accent size.',
            ],
            [
                'name' => '2 inches',
                'description' => 'Label/print measuring approximately 2 inches — standard small label size.',
            ],
            [
                'name' => '3 inches',
                'description' => 'Label/print measuring approximately 3 inches — medium label size.',
            ],
            [
                'name' => '4 inches',
                'description' => 'Label/print measuring approximately 4 inches — large label size.',
            ],
            [
                'name' => '1 cm from collar',
                'description' => 'Positioned about 1 cm below the collar seam — typical nape brand-label offset.',
            ],
            [
                'name' => '2 cm from collar',
                'description' => 'Positioned about 2 cm below the collar seam.',
            ],
            [
                'name' => '2 cm from hem',
                'description' => 'Positioned about 2 cm up from the bottom hem — typical care-label placement.',
            ],
            [
                'name' => 'Centered',
                'description' => 'Centered within the chosen placement area.',
            ],
            [
                'name' => 'Custom',
                'description' => 'Custom measurement — specify the exact detail in the label notes.',
            ],
        ];

        foreach ($items as $data) {
            PlacementMeasurement::updateOrCreate(
                ['name' => $data['name']],
                [
                    'description' => $data['description'],
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
        }
    }
}
