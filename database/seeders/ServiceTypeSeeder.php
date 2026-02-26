<?php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ServiceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ServiceType::insert(
            [
                'name' => 'Sew & Print / Embro',
                'description' => 'A comprehensive, end-to-end service that covers both the garment construction and the application of custom designs or logos.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Sew Only',
                'description' => 'A specialized manufacturing service focused strictly on the assembly, stitching, and construction of the garment.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Print / Embro Only',
                'description' => 'A finishing service dedicated solely to adding graphics or embroidery to pre-made blank apparel.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );
    }
}
