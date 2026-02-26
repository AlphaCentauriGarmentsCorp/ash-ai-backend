<?php

namespace Database\Seeders;

use App\Models\PrintMethod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;


class PrintMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [
            [
                'name' => 'Silkscreen',
                'description' => 'A traditional, long-lasting method that uses ink pressed through a mesh screen to create vibrant, durable designs.',
            ],
            [
                'name' => 'DTF',
                'description' => 'A modern heat-transfer process that allows for full-color, high-detail graphics on almost any fabric type.',
            ],
            [
                'name' => 'Sublimation',
                'description' => 'A specialized dyeing process that fuses ink directly into the fabric fibers, resulting in a smooth, fade-resistant finish.',
            ],
            [
                'name' => 'Embroidery',
                'description' => 'A premium technique that uses high-quality thread to stitch intricate, 3D designs directly onto the garment.',
            ],
            [
                'name' => 'High Density',
                'description' => 'A screen printing variation that uses thick, layered ink to create a raised, 3D texture you can feel.',
            ],
        ];

        foreach ($items as $data) {
            PrintMethod::updateOrCreate(
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
