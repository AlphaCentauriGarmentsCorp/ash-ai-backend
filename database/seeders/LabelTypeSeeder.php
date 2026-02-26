<?php

namespace Database\Seeders;

use App\Models\SizeLabel;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use PhpParser\Node\Stmt\Label;
use Illuminate\Support\Carbon;


class LabelTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [
            [
                'name' => 'Sew',
                'description' => 'A durable, physical fabric label stitched directly into the collar or seam for a traditional and premium feel.',
            ],
            [
                'name' => 'Print',
                'description' => 'A tagless option where size information is screen-printed or heat-transferred directly onto the inner fabric for maximum comfort.',
            ],
            [
                'name' => 'None',
                'description' => 'None',
            ],
        ];

        foreach ($items as $data) {
            SizeLabel::updateOrCreate(
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
