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
        $items = [
            [
                'name' => 'Standard',
                'description' => 'A classic, regular fit that sits naturally on the body for a clean and timeless look.',
            ],
            [
                'name' => 'Oversized',
                'description' => 'A loose, roomy fit with extra volume throughout for a relaxed and modern silhouette.',
            ],
            [
                'name' => 'Boxy',
                'description' => 'A wide, square-cut fit with a shorter length that creates a structured, straight-down shape.',
            ],
        ];

        foreach ($items as $data) {
            PatternType::updateOrCreate(
                ['name' => $data['name']],
                [
                    'description' => $data['description'],
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
        }
    }
}
