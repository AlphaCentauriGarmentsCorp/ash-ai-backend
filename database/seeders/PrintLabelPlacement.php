<?php

namespace Database\Seeders;

use App\Models\PrintLabelPlacement as ModelsPrintLabelPlacement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PrintLabelPlacement extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ModelsPrintLabelPlacement::insert(
            [
                'name' => 'Body Front',
                'description' => 'The primary chest and stomach area of the garment, ideal for main graphics or large logos.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Body Back',
                'description' => 'The full rear surface of the garment, often used for bold branding or high-visibility designs.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Sleeve Right',
                'description' => 'The outer surface of the right arm, perfect for secondary logos, icons, or text.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Sleeve Left',
                'description' => 'The outer surface of the left arm, commonly used for flag patches or small brand marks.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Pocket Right',
                'description' => 'The exterior surface of the right-side pocket, providing a subtle and functional placement for small details.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Pocket Left',
                'description' => 'The exterior surface of the left-side pocket, often used for symmetrical branding or minimalist accents.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Leg Right Front',
                'description' => 'The front-facing thigh or shin area of the right leg, high in visibility during movement.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Leg Right Back',
                'description' => 'The rear-facing area of the right leg, suitable for subtle branding or calf-level details.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Leg Right Side',
                'description' => 'The outer vertical seam area of the right leg, ideal for long vertical text or stripes.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Leg Left Front',
                'description' => 'The front-facing thigh or shin area of the left leg, a standard spot for athletic or team logos.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Leg Left Back',
                'description' => 'The rear-facing area of the left leg, used for balanced design elements on the back of the pants.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Leg Left Side',
                'description' => 'The outer vertical seam area of the left leg, perfect for side-taping or elongated graphics.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Hood',
                'description' => 'The outer surface of the head covering, offering a unique and edgy placement for centered or side-aligned prints.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        );
    }
}
