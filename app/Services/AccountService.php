<?php

namespace App\Services;

use App\Models\User;
use App\Models\EmployeeDetails;
use App\Models\UserAddress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;


class AccountService
{
    public function getAll(int $perPage = 50)
    {
        return User::with(['addresses', 'employeeDetail'])->whereJsonContains('domain_access', 'ash')->get();
    }

    public function create(array $data)
    {

        return DB::transaction(function () use ($data) {
            $avatarPath = null;

            if (!empty($data['profile'])) {
                $path = $data['profile']->store('avatars', 'public');
                $avatarPath = '/storage/' . $path;
            }

            $user = User::create([
                'name' => trim(
                    $data['first_name'] . ' ' .
                        (!empty($data['middle_name']) ? strtoupper(substr($data['middle_name'], 0, 1)) . '. ' : '') .
                        $data['last_name']
                ),
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'avatar' => $avatarPath,
                'domain_role' => $data['roles'],
                'domain_access' => ['ash'],
            ]);

            $files = [];

            if (!empty($data['additionalFiles'])) {
                foreach ($data['additionalFiles'] as $file) {
                    $path = $file->store('employee_files', 'public');
                    $files[] = '/storage/' . $path;
                }
            }

            EmployeeDetails::create([
                'user_id' => $user->id,
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'],
                'last_name' => $data['last_name'],
                'contact_number' => $data['contact_number'],
                'gender' => $data['gender'],
                'civil_status' => $data['civil_status'],
                'birthdate' => $data['birthdate'],
                'position' => $data['position'],
                'department' => $data['department'],
                'pagibig' => $data['pagibig'] ?? null,
                'sss' => $data['sss'] ?? null,
                'philhealth' => $data['philhealth'] ?? null,
                'files' => !empty($files) ? json_encode($files) : null,
            ]);

            UserAddress::create([
                'user_id' => $user->id,
                'type' => 'current',
                'street' => $data['currentStreet'] ?? null,
                'brangay' => $data['currentBarangay'] ?? null,
                'city' => $data['currentCity'] ?? null,
                'province' => $data['currentProvince'] ?? null,
                'postal' => $data['currentPostalCode'] ?? null,
                'country' => 'Philippines',
            ]);

            UserAddress::create([
                'user_id' => $user->id,
                'type' => 'permanent',
                'street' => $data['permanentStreet'] ?? null,
                'brangay' => $data['permanentBarangay'] ?? null,
                'city' => $data['permanentCity'] ?? null,
                'province' => $data['permanentProvince'] ?? null,
                'postal' => $data['permanentPostalCode'] ?? null,
                'country' => 'Philippines',
            ]);

            return $user;
        });
    }
}
