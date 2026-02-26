<?php

namespace Database\Seeders;

use App\Models\PatternType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PatternTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PatternType::insert([
            [
                'name' => 'Standard',
                'description' => 'A classic, regular fit that sits naturally on the body for a clean and timeless look.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Oversized',
                'description' => 'A loose, roomy fit with extra volume throughout for a relaxed and modern silhouette.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Boxy',
                'description' => 'A wide, square-cut fit with a shorter length that creates a structured, straight-down shape.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}
