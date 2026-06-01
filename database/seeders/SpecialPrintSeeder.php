<?php

namespace Database\Seeders;

use App\Models\SpecialPrint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SpecialPrintSeeder extends Seeder
{
    /**
     * Special print add-ons available under the Silkscreen print method.
     * Each adds an editable per-color surcharge (PricingSetting:
     * SPECIAL_PRINT_PER_COLOR). Superadmin-managed via Drop Down Settings.
     */
    public function run(): void
    {
        $items = [
            [
                'name'        => 'High Density',
                'description' => 'Thick, layered ink that creates a raised, 3D texture you can feel.',
            ],
            [
                'name'        => 'Puff',
                'description' => 'Ink that expands when heated for a soft, raised, puffy finish.',
            ],
            [
                'name'        => 'Others',
                'description' => 'Any other special silkscreen finish agreed with the client.',
            ],
        ];

        foreach ($items as $data) {
            SpecialPrint::updateOrCreate(
                ['name' => $data['name']],
                [
                    'description' => $data['description'],
                    'created_at'  => Carbon::now(),
                    'updated_at'  => Carbon::now(),
                ]
            );
        }
    }
}
