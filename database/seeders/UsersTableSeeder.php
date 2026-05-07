<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

class UsersTableSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'csr', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'graphic_artist', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'finance', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'logistics', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'screen_maker', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'cutter', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'printer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'sewer', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'qa', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'packer', 'guard_name' => 'web']);

        $superadmin = User::updateOrCreate(
            ['email' => 'superadmin@com'],
            [
                'name' => 'Super Admin',
                'username' => 'superadmin',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['superadmin'],
                'domain_access' => ['ash'],
            ]
        );
        $superadmin->syncRoles(['superadmin']);

        $admin = User::updateOrCreate(
            ['email' => 'admin@com'],
            [
                'name' => 'Admin User',
                'username' => 'admin',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['admin'],
                'domain_access' => ['ash'],
            ]
        );
        $admin->syncRoles(['admin']);

        $csr = User::updateOrCreate(
            ['email' => 'csr@com'],
            [
                'name' => 'CSR User',
                'username' => 'csr',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['csr'],
                'domain_access' => ['ash'],
            ]
        );
        $csr->syncRoles(['csr']);

        $reeferCustomer = User::updateOrCreate(
            ['email' => 'reefer@com'],
            [
                'name' => 'John Doe',
                'username' => 'johndoe',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['customer'],
                'domain_access' => ['reefer'],
            ]
        );
        $reeferCustomer->syncRoles(['customer']);

        $sorbetesCustomer = User::updateOrCreate(
            ['email' => 'sorbetes@com'],
            [
                'name' => 'Juan Delacruz',
                'username' => 'juanDc',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['customer'],
                'domain_access' => ['sorbetes'],
            ]
        );
        $sorbetesCustomer->syncRoles(['customer']);

        $graphicArtist = User::updateOrCreate(
            ['email' => 'artist@com'],
            [
                'name' => 'Graphic Artist',
                'username' => 'graphicartist',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['graphic_artist'],
                'domain_access' => ['ash'],
            ]
        );
        $graphicArtist->syncRoles(['graphic_artist']);

        $finance = User::updateOrCreate(
            ['email' => 'finance@com'],
            [
                'name' => 'Finance User',
                'username' => 'finance',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['finance'],
                'domain_access' => ['ash'],
            ]
        );
        $finance->syncRoles(['finance']);

        $logistics = User::updateOrCreate(
            ['email' => 'logistics@com'],
            [
                'name' => 'Logistics User',
                'username' => 'logistics',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['logistics'],
                'domain_access' => ['ash'],
            ]
        );
        $logistics->syncRoles(['logistics']);

        $screenMaker = User::updateOrCreate(
            ['email' => 'screenmaker@com'],
            [
                'name' => 'Screen Maker',
                'username' => 'screenmaker',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['screen_maker'],
                'domain_access' => ['ash'],
            ]
        );
        $screenMaker->syncRoles(['screen_maker']);

        $cutter = User::updateOrCreate(
            ['email' => 'cutter@com'],
            [
                'name' => 'Cutter',
                'username' => 'cutter',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['cutter'],
                'domain_access' => ['ash'],
            ]
        );
        $cutter->syncRoles(['cutter']);

        $printer = User::updateOrCreate(
            ['email' => 'printer@com'],
            [
                'name' => 'Printer',
                'username' => 'printer',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['printer'],
                'domain_access' => ['ash'],
            ]
        );
        $printer->syncRoles(['printer']);

        $sewer = User::updateOrCreate(
            ['email' => 'sewer@com'],
            [
                'name' => 'Sewer',
                'username' => 'sewer',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['sewer'],
                'domain_access' => ['ash'],
            ]
        );
        $sewer->syncRoles(['sewer']);

        $qa = User::updateOrCreate(
            ['email' => 'qa@com'],
            [
                'name' => 'QA User',
                'username' => 'qa',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['qa'],
                'domain_access' => ['ash'],
            ]
        );
        $qa->syncRoles(['qa']);

        $packer = User::updateOrCreate(
            ['email' => 'packer@com'],
            [
                'name' => 'Packer User',
                'username' => 'packer',
                'email_verified_at' => Carbon::now(),
                'password' => Hash::make('password'),
                'remember_token' => null,
                'avatar' => null,
                'otp' => null,
                'otp_expires_at' => null,
                'last_verified' => Carbon::now(),
                'domain_role' => ['packer'],
                'domain_access' => ['ash'],
            ]
        );
        $packer->syncRoles(['packer']);
    }
}
