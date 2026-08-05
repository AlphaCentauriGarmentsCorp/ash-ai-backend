<?php

namespace Database\Seeders\Storefront;

use App\Models\Storefront\Discount;
use Illuminate\Database\Seeder;

class DiscountSeeder extends Seeder
{
    public function run(): void
    {
        // used_count is deliberately absent from every payload: re-seeding must not
        // hand back uses that have already been spent.
        $codes = [
            [
                'code' => 'WAVE10',
                'type' => 'percent',
                'value' => 10,
                'description' => '10% off. No minimum, no catch.',
            ],
            [
                'code' => 'TIDAL200',
                'type' => 'fixed',
                'value' => 200,
                'min_subtotal' => 1500,
                'description' => '₱200 off orders over ₱1,500.',
            ],
            [
                'code' => 'FIRSTDROP',
                'type' => 'percent',
                'value' => 15,
                'min_subtotal' => 1000,
                'per_user_limit' => 1,
                'description' => '15% off your first drop. One per account.',
            ],
            [
                'code' => 'SALT500',
                'type' => 'fixed',
                'value' => 500,
                'min_subtotal' => 3000,
                'max_uses' => 100,
                'description' => '₱500 off orders over ₱3,000. First 100 only.',
            ],
            // Expired on purpose — the refusal path needs something to refuse.
            [
                'code' => 'LOWTIDE',
                'type' => 'percent',
                'value' => 20,
                'ends_at' => now()->subDay(),
                'description' => 'Last summer\'s code. Kept so the expiry path is testable.',
            ],
        ];

        foreach ($codes as $attributes) {
            Discount::updateOrCreate(['code' => $attributes['code']], $attributes);
        }
    }
}
