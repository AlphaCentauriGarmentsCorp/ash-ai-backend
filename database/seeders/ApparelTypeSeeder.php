<?php

namespace Database\Seeders;

use App\Models\ApparelType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ApparelTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $items = [
            [
                'name' => 'Tshirt - Premium',
                'description' => 'A refined version of the classic tee, crafted from high-quality, soft-touch fabric for a more polished feel.',

            ],
            [
                'name' => 'Tshirt - Heavyweight',
                'description' => 'A thick, durable shirt that maintains its shape well and provides a premium, structured drape.',
            ],
            [
                'name' => 'Tshirt - Acid Wash',
                'description' => 'A vintage-inspired tee featuring a unique, faded marble effect created through a specialized washing process.',
            ],
            [
                'name' => 'Tshirt - Tiedye',
                'description' => 'A vibrant, artistic shirt characterized by bold, hand-dyed patterns where no two pieces are exactly alike.',
            ],
            [
                'name' => 'Hoodie - Heavyweight',
                'description' => 'A thick, high-density fleece hoodie designed for maximum warmth and a sturdy, premium silhouette.',
            ],
            [
                'name' => 'Hoodie - Zipper',
                'description' => 'A versatile layering piece with a full-length front zipper for easy wear and temperature control.',
            ],
            [
                'name' => 'Jogging Pants',
                'description' => 'Comfortable, athletic bottoms with an elastic waistband and cuffed ankles, perfect for lounging or movement.',
            ],
            [
                'name' => 'Trackshorts',
                'description' => 'Lightweight, breathable shorts designed for athletic performance and active lifestyles.',
            ],
            [
                'name' => 'Polo Shirt',
                'description' => 'A smart-casual classic featuring a structured collar and button placket for a clean, professional look.',
            ],
            [
                'name' => 'Long sleeve',
                'description' => 'A full-length sleeve top that provides extra coverage while maintaining a lightweight, comfortable feel.',
            ],
            [
                'name' => 'Tanktop',
                'description' => 'A sleeveless, breathable top designed for maximum airflow and freedom of movement during warm weather or workouts.',
            ],
            [
                'name' => 'Shorts',
                'description' => 'A standard-length bottom designed for casual comfort and ease of movement in everyday settings.',
            ],
            [
                'name' => 'Mesh shorts',
                'description' => 'Highly breathable, perforated fabric shorts typically used for basketball or high-intensity sports.',
            ],
            [
                'name' => 'Totebag',
                'description' => 'A spacious and durable open-top bag with sturdy handles, ideal for carrying daily essentials in style.',
            ],
        ];

        foreach ($items as $data) {
            ApparelType::updateOrCreate(
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
