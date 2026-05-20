<?php

namespace Database\Seeders;

use App\Models\PackingChecklistItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phase 7-B Bundle 1 — Seed the 7 canonical packing checklist items.
 *
 * Per the spec doc: "fold and pack, hangtag, size sticker, OPP plastic,
 * freebie, QR label, box label". Order follows the typical packing
 * workflow (garment first, then accessories, then box-level labels).
 */
class PackingChecklistItemSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $items = [
            ['slug' => 'fold_and_pack',  'label' => 'Fold and Pack',  'display_order' => 10],
            ['slug' => 'hangtag',        'label' => 'Hangtag',        'display_order' => 20],
            ['slug' => 'size_sticker',   'label' => 'Size Sticker',   'display_order' => 30],
            ['slug' => 'opp_plastic',    'label' => 'OPP Plastic',    'display_order' => 40],
            ['slug' => 'freebie',        'label' => 'Freebie',        'display_order' => 50],
            ['slug' => 'qr_label',       'label' => 'QR Label',       'display_order' => 60],
            ['slug' => 'box_label',      'label' => 'Box Label',      'display_order' => 70],
        ];

        foreach ($items as $data) {
            PackingChecklistItem::updateOrCreate(
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
