<?php

namespace Database\Seeders;

use App\Models\NotificationSetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Phase 7-B Bundle 1 — Default notification settings.
 *
 * Currently seeds two rows:
 *
 *   qa_reject_alert_threshold_pcs   (int)  = 5
 *   qa_reject_alert_threshold_pct   (float) = 0.10   (= 10%)
 *
 * When a QA/Packer task is submitted, the Super Admin is notified
 * if the rejected-piece count exceeds EITHER threshold for the order.
 * CSR is notified regardless.
 *
 * These values are read by NotificationService at notify-time, so
 * they're tunable in production via a simple UPDATE without a deploy.
 */
class NotificationSettingsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $rows = [
            [
                'key'         => 'qa_reject_alert_threshold_pcs',
                'value_json'  => 5,
                'description' => 'Super Admin reject alert: piece-count threshold. '
                    . 'A submitted QA task with rejects ≥ this number triggers a Super Admin notification.',
            ],
            [
                'key'         => 'qa_reject_alert_threshold_pct',
                'value_json'  => 0.10,
                'description' => 'Super Admin reject alert: percentage threshold (0.10 = 10%). '
                    . 'A submitted QA task with reject ratio ≥ this fraction of order quantity '
                    . 'triggers a Super Admin notification.',
            ],
        ];

        foreach ($rows as $row) {
            NotificationSetting::updateOrCreate(
                ['key' => $row['key']],
                [
                    'value_json'  => $row['value_json'],
                    'description' => $row['description'],
                ],
            );
        }
    }
}
