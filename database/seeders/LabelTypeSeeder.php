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
        SizeLabel::insert(
            [
                'name' => 'Sew',
                'description' => 'A durable, physical fabric label stitched directly into the collar or seam for a traditional and premium feel.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Print',
                'description' => 'A tagless option where size information is screen-printed or heat-transferred directly onto the inner fabric for maximum comfort.',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'None',
                'description' => 'None',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        );
    }
}
