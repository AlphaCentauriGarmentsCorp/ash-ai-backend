<?php

namespace Database\Seeders;

use App\Models\FabricType;
use Illuminate\Database\Seeder;

/**
 * Change 7.1 — seed Fabric Type options.
 *
 * The three fabric types Sorbetes uses. Edit this array freely; it is
 * idempotent (firstOrCreate by name), so re-running won't create duplicates.
 *
 *   php artisan db:seed --class=FabricTypeSeeder
 */
class FabricTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'CVC',
            'TC',
            'Brushed Cotton',
        ];

        foreach ($types as $name) {
            FabricType::firstOrCreate(['name' => $name]);
        }
    }
}
