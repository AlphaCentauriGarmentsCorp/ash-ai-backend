<?php

namespace Database\Seeders;

use App\Models\QaChecklistItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phase 7-B Bundle 1 — Seed the 7 canonical QA checklist items.
 *
 * These mirror the spec doc verbatim ("correct print, correct size,
 * correct color, no stains, no damage, correct label, correct
 * quantity"). v1 always shows all 7; future versions may filter by
 * active=false to retire an item without losing historical data.
 */
class QaChecklistItemSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $items = [
            ['slug' => 'correct_print',     'label' => 'Correct Print',     'display_order' => 10],
            ['slug' => 'correct_size',      'label' => 'Correct Size',      'display_order' => 20],
            ['slug' => 'correct_color',     'label' => 'Correct Color',     'display_order' => 30],
            ['slug' => 'no_stains',         'label' => 'No Stains',         'display_order' => 40],
            ['slug' => 'no_damage',         'label' => 'No Damage',         'display_order' => 50],
            ['slug' => 'correct_label',     'label' => 'Correct Label',     'display_order' => 60],
            ['slug' => 'correct_quantity',  'label' => 'Correct Quantity',  'display_order' => 70],
        ];

        foreach ($items as $data) {
            QaChecklistItem::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'label'         => $data['label'],
                    'display_order' => $data['display_order'],
                    'active'        => true,
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
            );
        }
    }
}
