<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UsersTableSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->insert([
            [
                'name'               => 'Admin User',
                'username'           => 'admin',
                'email'              => 'admin@com',
                'email_verified_at'  => Carbon::now(),
                'password'           => Hash::make('password'),
                'remember_token'     => null,
                'avatar'             => null,
                'otp'                => null,
                'otp_expires_at'     => null,
                'last_verified'      => Carbon::now(),
                'domain_role'        => 'admin',
                'domain_access'      => '["ash"]',
                'created_at'         => Carbon::now(),
                'updated_at'         => Carbon::now(),
            ],
            [
                'name'               => 'John Doe',
                'username'           => 'johndoe',
                'email'              => 'john@example.com',
                'email_verified_at'  => Carbon::now(),
                'password'           => Hash::make('password'),
                'remember_token'     => null,
                'avatar'             => null,
                'otp'                => null,
                'otp_expires_at'     => null,
                'last_verified'      => Carbon::now(),
                'domain_role'        => 'user',
                'domain_access'      => "['ash']",
                'created_at'         => Carbon::now(),
                'updated_at'         => Carbon::now(),
            ],
        ]);
    }
}
