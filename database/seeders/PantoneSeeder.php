<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Pantone;

class PantoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $items = [
            // Hoodie Section
            ['name' => 'Dark Navy', 'hexcolor' => '#131E29', 'pantone_code' => '289C'],
            ['name' => 'Deep Blue', 'hexcolor' => '#03257E', 'pantone_code' => '3591C'],
            ['name' => 'Light Beige', 'hexcolor' => '#EFDBB2', 'pantone_code' => '7506C'],
            ['name' => 'Light Gray', 'hexcolor' => '#C8C3B3', 'pantone_code' => '4239C'],
            ['name' => 'Ash Gray', 'hexcolor' => '#8C8985', 'pantone_code' => '2332 C'],
            ['name' => 'Soft Pink', 'hexcolor' => '#D0C4C5', 'pantone_code' => '434C'],
            ['name' => 'Forest Green', 'hexcolor' => '#304F42', 'pantone_code' => '4210 C'],
            ['name' => 'Olive Green', 'hexcolor' => '#6C5D34', 'pantone_code' => '7561 C'],
            ['name' => 'Purple', 'hexcolor' => '#5E366E', 'pantone_code' => '7665 C'],
            ['name' => 'Lavender', 'hexcolor' => '#AF95D3', 'pantone_code' => '2073 C'],
            ['name' => 'Brownish Beige', 'hexcolor' => '#A58877', 'pantone_code' => '2471 C'],
            ['name' => 'Crimson Red', 'hexcolor' => '#C5003E', 'pantone_code' => '1935 C'],
            ['name' => 'Black', 'hexcolor' => '#101820', 'pantone_code' => 'Black 6 C'],
            ['name' => 'Light Grayish Purple', 'hexcolor' => '#D8D7DF', 'pantone_code' => '5315 C'],

            // 280 GSM Section
            ['name' => 'Pale Yellow Cream', 'hexcolor' => '#F2F0A1', 'pantone_code' => '0131 C'],
            ['name' => 'Mocha', 'hexcolor' => '#BAA58D', 'pantone_code' => '4253 C'],
            ['name' => 'Mustard', 'hexcolor' => '#9F7D23', 'pantone_code' => '7557 C'],
            ['name' => 'Brown', 'hexcolor' => '#4F2C1D', 'pantone_code' => '4625 C'],
            ['name' => 'Rust', 'hexcolor' => '#864A33', 'pantone_code' => '7581 C'],
            ['name' => 'Red', 'hexcolor' => '#7C2529', 'pantone_code' => '1815 C'],
            ['name' => 'Bright Red', 'hexcolor' => '#BA0020', 'pantone_code' => '3517 C'],
            ['name' => 'Light Gray', 'hexcolor' => '#A2AAAD', 'pantone_code' => '429 C'],
            ['name' => 'Dk. Gray', 'hexcolor' => '#333F48', 'pantone_code' => '432 C'],
            ['name' => 'Royal Blue', 'hexcolor' => '#00A88E', 'pantone_code' => '3581 C'],
            ['name' => 'China Blue', 'hexcolor' => '#6A6A8E', 'pantone_code' => '4141 C'],
            ['name' => 'Powder Blue', 'hexcolor' => '#B9D9EB', 'pantone_code' => '290 C'],
            ['name' => 'Dk. Yellow', 'hexcolor' => '#D69A2D', 'pantone_code' => '7563 C'],
            ['name' => 'Lt. Yellow', 'hexcolor' => '#F1B434', 'pantone_code' => '143 C'],
            ['name' => 'Mint Blue', 'hexcolor' => '#99D9EA', 'pantone_code' => '630 C'],
            ['name' => 'Dark Green', 'hexcolor' => '#183028', 'pantone_code' => '5535 C'],
            ['name' => 'Fatigue', 'hexcolor' => '#3E4827', 'pantone_code' => '5743 C'],
            ['name' => 'Peach', 'hexcolor' => '#F4C1C4', 'pantone_code' => '692 C'],
            ['name' => 'Light Peach', 'hexcolor' => '#FFE0A9', 'pantone_code' => '1565 C'],

            // 220-240 GSM Section
            ['name' => 'Light Beige', 'hexcolor' => '#131E29', 'pantone_code' => '289C'],
            ['name' => 'Deep Blue', 'hexcolor' => '#03257E', 'pantone_code' => '3591C'],
            ['name' => 'Light Beige', 'hexcolor' => '#EFDBB2', 'pantone_code' => '7506C'],
            ['name' => 'Light Gray', 'hexcolor' => '#C8C3B3', 'pantone_code' => '4239C'],
            ['name' => 'Ash Gray', 'hexcolor' => '#8C8985', 'pantone_code' => '2332 C'],
            ['name' => 'Soft Pink', 'hexcolor' => '#D0C4C5', 'pantone_code' => '434C'],
            ['name' => 'Forest Green', 'hexcolor' => '#304F42', 'pantone_code' => '4210 C'],
            ['name' => 'Olive Green', 'hexcolor' => '#6C5D34', 'pantone_code' => '7561 C'],
            ['name' => 'Purple', 'hexcolor' => '#5E366E', 'pantone_code' => '7665 C'],
            ['name' => 'Lavender', 'hexcolor' => '#AF95D3', 'pantone_code' => '2073 C'],
            ['name' => 'Brownish Beige', 'hexcolor' => '#A58877', 'pantone_code' => '2471 C'],
            ['name' => 'Crimson Red', 'hexcolor' => '#C5003E', 'pantone_code' => '1935 C'],
            ['name' => 'Black', 'hexcolor' => '#101820', 'pantone_code' => 'Black 6 C'],
            ['name' => 'Light Grayish Purple', 'hexcolor' => '#D8D7DF', 'pantone_code' => '5315 C'],
            ['name' => 'Pale Yellow Cream', 'hexcolor' => '#F2F0A1', 'pantone_code' => '0131 C'],
            ['name' => 'Mocha', 'hexcolor' => '#BAA58D', 'pantone_code' => '4253 C'],
            ['name' => 'Mustard', 'hexcolor' => '#9F7D23', 'pantone_code' => '7557 C'],
            ['name' => 'Brown', 'hexcolor' => '#4F2C1D', 'pantone_code' => '4625 C'],
            ['name' => 'Rust', 'hexcolor' => '#864A33', 'pantone_code' => '7581 C'],
            ['name' => 'Red', 'hexcolor' => '#7C2529', 'pantone_code' => '1815 C'],
            ['name' => 'Bright Red', 'hexcolor' => '#BA0020', 'pantone_code' => '3517 C'],
            ['name' => 'Light Gray', 'hexcolor' => '#A2AAAD', 'pantone_code' => '429 C'],
            ['name' => 'Dk. Gray', 'hexcolor' => '#333F48', 'pantone_code' => '432 C'],
            ['name' => 'Royal Blue', 'hexcolor' => '#00A88E', 'pantone_code' => '3581 C'],
            ['name' => 'China Blue', 'hexcolor' => '#6A6A8E', 'pantone_code' => '4141 C'],
            ['name' => 'Powder Blue', 'hexcolor' => '#B9D9EB', 'pantone_code' => '290 C'],
            ['name' => 'Dk. Yellow', 'hexcolor' => '#D69A2D', 'pantone_code' => '7563 C'],
            ['name' => 'Lt. Yellow', 'hexcolor' => '#F1B434', 'pantone_code' => '143 C'],
            ['name' => 'Mint Blue', 'hexcolor' => '#99D9EA', 'pantone_code' => '630 C'],
            ['name' => 'Dark Green', 'hexcolor' => '#183028', 'pantone_code' => '5535 C'],
            ['name' => 'Fatigue', 'hexcolor' => '#3E4827', 'pantone_code' => '5743 C'],
            ['name' => 'Peach', 'hexcolor' => '#F4C1C4', 'pantone_code' => '692 C'],
            ['name' => 'Light Apple Green', 'hexcolor' => '#8EDD65', 'pantone_code' => '2292 C'],
            ['name' => 'Christmas Green', 'hexcolor' => '#006B38', 'pantone_code' => '7736 C'],
            ['name' => 'Light Fatigue Green', 'hexcolor' => '#006B4F', 'pantone_code' => '5753 C'],
            ['name' => 'Milo Green', 'hexcolor' => '#05A31D', 'pantone_code' => '3529 C'],
            ['name' => 'Fatigue Green', 'hexcolor' => '#4E4934', 'pantone_code' => '7771 C'],
            ['name' => 'Emerald Green', 'hexcolor' => '#007A53', 'pantone_code' => '341 C'],
            ['name' => 'Military Fatigue', 'hexcolor' => '#6D654F', 'pantone_code' => '4227 C'],
            ['name' => 'Aqua Green', 'hexcolor' => '#00A9C9', 'pantone_code' => '3115 C'],
            ['name' => 'Apple Green', 'hexcolor' => '#7CCC6E', 'pantone_code' => '2269 C'],
            ['name' => 'Blue Green', 'hexcolor' => '#5998C4', 'pantone_code' => '2170 C'],
            ['name' => 'Jade Green', 'hexcolor' => '#00A3B8', 'pantone_code' => '322 C'],
            ['name' => 'China Blue', 'hexcolor' => '#5C88DA', 'pantone_code' => '2718 C'],
            ['name' => 'Light Aqua Blue', 'hexcolor' => '#005698', 'pantone_code' => '2185 C'],
            ['name' => 'Dark China Blue', 'hexcolor' => '#3B3FB6', 'pantone_code' => '2369 C'],
            ['name' => 'Light Royal Blue', 'hexcolor' => '#001A70', 'pantone_code' => '662 C'],
            ['name' => 'Dark Aqua Blue', 'hexcolor' => '#006298', 'pantone_code' => '2186 C'],
            ['name' => 'Dark Royal Blue', 'hexcolor' => '#041E42', 'pantone_code' => '282 C'],
            ['name' => 'Peacock Blue', 'hexcolor' => '#326295', 'pantone_code' => '653 C'],
            ['name' => 'Navy Blue', 'hexcolor' => '#1B1C34', 'pantone_code' => '4146 C'],
            ['name' => 'Blue Violet', 'hexcolor' => '#8BB8E8', 'pantone_code' => '278 C'],
            ['name' => 'Lavender', 'hexcolor' => '#A68ACA', 'pantone_code' => '2086 C'],
            ['name' => 'Violet', 'hexcolor' => '#2E1A47', 'pantone_code' => '2695 C'],
            ['name' => 'Burgundy', 'hexcolor' => '#5D2A2C', 'pantone_code' => '490 C'],
            ['name' => 'Khaki Brown', 'hexcolor' => '#8C857B', 'pantone_code' => '403 C'],
            ['name' => 'Choco Brown', 'hexcolor' => '#623412', 'pantone_code' => '732 C'],
            ['name' => 'Khaki', 'hexcolor' => '#96856E', 'pantone_code' => '4270 C'],
            ['name' => 'Special Gray', 'hexcolor' => '#B2AAAC', 'pantone_code' => '4282 C'],
            ['name' => 'Gray', 'hexcolor' => '#403A60', 'pantone_code' => '4265 C'],
        ];

        foreach ($items as $data) {
            Pantone::create($data);  // Insert each Pantone record into the database
        }
    }
}