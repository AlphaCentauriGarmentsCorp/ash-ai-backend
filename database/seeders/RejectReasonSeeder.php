<?php

namespace Database\Seeders;

use App\Models\RejectReason;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phase 7-B Bundle 1 — Seed the 7 canonical reject reasons.
 *
 * Order matters: `display_order` drives the dropdown order shown
 * to packers in the Add Reject / Add Repair modals.
 *
 * `is_fabric` flags `fabric_issue` (and only that) so the notification
 * service can fan out to the Cutter when this reason is cited, per
 * PDF §6 ("if reject due to fabric: notify Cutter").
 */
class RejectReasonSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $items = [
            ['slug' => 'fabric_issue',  'label' => 'Fabric Issue',  'is_fabric' => true,  'display_order' => 10],
            ['slug' => 'print_issue',   'label' => 'Print Issue',   'is_fabric' => false, 'display_order' => 20],
            ['slug' => 'sewing_issue',  'label' => 'Sewing Issue',  'is_fabric' => false, 'display_order' => 30],
            ['slug' => 'stain',         'label' => 'Stain',         'is_fabric' => false, 'display_order' => 40],
            ['slug' => 'wrong_size',    'label' => 'Wrong Size',    'is_fabric' => false, 'display_order' => 50],
            ['slug' => 'wrong_label',   'label' => 'Wrong Label',   'is_fabric' => false, 'display_order' => 60],
            ['slug' => 'damaged',       'label' => 'Damaged',       'is_fabric' => false, 'display_order' => 70],
        ];

        foreach ($items as $data) {
            RejectReason::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'label'         => $data['label'],
                    'is_fabric'     => $data['is_fabric'],
                    'display_order' => $data['display_order'],
                    'active'        => true,
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ],
            );
        }
    }
}
