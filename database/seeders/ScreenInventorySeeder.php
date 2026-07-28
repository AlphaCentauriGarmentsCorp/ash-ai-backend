<?php

namespace Database\Seeders;

use App\Models\Screens;
use Illuminate\Database\Seeder;

/**
 * ScreenInventorySeeder
 *
 * Seeds the Screen Inventory from the client's "SCREEN_DATA.pdf" physical
 * count (19 screens on the rack, 10 with a legible sticker).
 *
 * Rows with "No address available (walang sticker sa screen)" — Widescope
 * Advertising Agency (x2), Grind (x4), Car Ride (x2), The Drip Kartel — are
 * deliberately excluded per Josh's instruction: address, mesh_count, and
 * size are all required fields on Screens/Store, and a screen with no
 * sticker has no reliable size/mesh either.
 *
 * IDEMPOTENT — keyed on `address` (the sticker code, e.g. "21x29-120-514"),
 * since it is the only field that is actually unique per physical screen;
 * `name` repeats across screens that share a design (two "Mandala Eye"
 * screens, two "Pulled Over" screens, etc.). Safe to run repeatedly:
 *
 *     php artisan db:seed --class=ScreenInventorySeeder
 *
 * `status` is deliberately left null — matches the existing convention for
 * screens created before the status lifecycle existed (SM Rework CP4):
 * both ScreenMakerPortalService::availableScreens() and the frontend's
 * screenStatusMeta() treat a null status the same as 'available'.
 */
class ScreenInventorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->screens() as $row) {
            Screens::firstOrCreate(
                ['address' => $row['address']],
                [
                    'name'       => $row['name'],
                    'size'       => $row['size'],
                    'mesh_count' => $row['mesh_count'],
                ]
            );
        }
    }

    /**
     * The 10 screens from SCREEN_DATA.pdf that have a legible sticker
     * (address + mesh count + screen size). Screens without a sticker are
     * excluded — see class docblock.
     *
     * @return array<int, array<string, string>>
     */
    private function screens(): array
    {
        return [
            ['name' => 'Mandala Eye',       'address' => '21x29-120-514', 'mesh_count' => '120', 'size' => '21x29'],
            ['name' => 'Mandala Eye',       'address' => '21x29-120-513', 'mesh_count' => '120', 'size' => '21x29'],
            ['name' => 'Lost Unicorn',      'address' => '21x25-120-153', 'mesh_count' => '120', 'size' => '21x25'],
            ['name' => 'Life to Be',        'address' => '21x25-120-233', 'mesh_count' => '120', 'size' => '21x25'],
            ['name' => 'Room',              'address' => '21x25-120-459', 'mesh_count' => '120', 'size' => '21x25'],
            ['name' => 'Cartel Polo',       'address' => '21x29-120-004', 'mesh_count' => '120', 'size' => '21x29'],
            ['name' => 'Pulled Over',       'address' => '21x29-120-528', 'mesh_count' => '120', 'size' => '21x29'],
            ['name' => 'Pulled Over',       'address' => '21x29-120-529', 'mesh_count' => '120', 'size' => '21x29'],
            ['name' => 'Anomaly Detected',  'address' => '21x29-120-480', 'mesh_count' => '120', 'size' => '21x29'],
            ['name' => 'Anomaly Detected',  'address' => '21x29-120-481', 'mesh_count' => '120', 'size' => '21x29'],
        ];
    }
}
