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
        // User::factory(10)->create();

        Employees::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
