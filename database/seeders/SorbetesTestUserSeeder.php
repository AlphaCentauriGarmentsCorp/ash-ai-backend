<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Creates ONE Sorbetes storefront test customer, in isolation.
 *
 * Safe to run on production: it only touches this single account (updateOrCreate
 * keyed on the email) and never modifies any staff / ASH user.
 *
 *   DO NOT run UsersTableSeeder on production to get this user — that seeder
 *   updateOrCreate's every staff account and resets their passwords to
 *   "password". Run THIS class instead.
 *
 * Run:    php artisan db:seed --class=SorbetesTestUserSeeder
 * Login:  (Sorbetes storefront)  sorbetes@com  /  password   via  POST /api/v2/login/sorbetes
 *
 * Mirrors the existing sorbetes@com entry in UsersTableSeeder, so running either
 * one produces the same row.
 */
class SorbetesTestUserSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure the Spatie 'customer' role exists (web guard).
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $user = User::updateOrCreate(
            ['email' => 'sorbetes@com'],
            [
                'name'              => 'Juan Delacruz',
                'username'          => 'juanDc',
                'email_verified_at' => Carbon::now(),
                'password'          => Hash::make('password'),
                'remember_token'    => null,
                'avatar'            => null,
                'otp'               => null,
                'otp_expires_at'    => null,
                'last_verified'     => Carbon::now(),
                'domain_role'       => ['customer'],
                'domain_access'     => ['sorbetes'],
            ]
        );

        $user->syncRoles(['customer']);

        $this->command->info('Sorbetes test user ready:  sorbetes@com  /  password');
    }
}
