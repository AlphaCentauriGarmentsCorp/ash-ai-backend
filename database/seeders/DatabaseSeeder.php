<?php

namespace Database\Seeders;

use App\Models\Employees;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RbacSeeder::class);
        $this->call(UsersTableSeeder::class);
        $this->call(ClientSeeder::class);
        $this->call(ApparelTypeSeeder::class);
        $this->call(LabelTypeSeeder::class);
        $this->call(PatternTypeSeeder::class);
        $this->call(PrintMethodSeeder::class);
        $this->call(ServiceTypeSeeder::class);
        $this->call(PrintLabelPlacements::class);
        $this->call(PantoneSeeder::class);
    }
}
