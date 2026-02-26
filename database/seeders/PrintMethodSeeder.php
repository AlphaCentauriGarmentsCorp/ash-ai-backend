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
        PrintMethod::insert(
            [
                'name' => 'Silkscreen',
                'description' => 'A traditional, long-lasting method that uses ink pressed through a mesh screen to create vibrant, durable designs.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'DTF',
                'description' => 'A modern heat-transfer process that allows for full-color, high-detail graphics on almost any fabric type.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Sublimation',
                'description' => 'A specialized dyeing process that fuses ink directly into the fabric fibers, resulting in a smooth, fade-resistant finish.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Embroidery',
                'description' => 'A premium technique that uses high-quality thread to stitch intricate, 3D designs directly onto the garment.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'High Density',
                'description' => 'A screen printing variation that uses thick, layered ink to create a raised, 3D texture you can feel.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        );
    }
}
