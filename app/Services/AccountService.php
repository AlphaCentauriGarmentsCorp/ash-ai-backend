<?php

namespace App\Services;

use App\Models\User;
use App\Models\EmployeeDetails;
use App\Models\UserAddress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;


class AccountService
{
    public function getAll(int $perPage = 50)
    {
        return User::with(['addresses', 'employeeDetail'])->whereJsonContains('domain_access', 'ash')->get();
    }

    /**
     * Fetch a single ASH employee with its relations (for View / Edit).
     */
    public function getById(int $id): ?User
    {
        return User::with(['addresses', 'employeeDetail'])
            ->whereJsonContains('domain_access', 'ash')
            ->find($id);
    }

    /**
     * Update an employee account across users, employee_details, and addresses.
     *
     * Mirrors create(): all writes happen in a single transaction. Fields are
     * applied only when present so partial edits are safe. Password is updated
     * only when a non-empty value is supplied (blank = unchanged). A new profile
     * image replaces the old avatar; newly uploaded additionalFiles are appended.
     */
    public function update(int $id, array $data): ?User
    {
        $user = User::with(['employeeDetail', 'addresses'])->find($id);

        if (!$user) {
            return null;
        }

        return DB::transaction(function () use ($user, $data) {
            // --- users table ---
            $userUpdates = [];

            if (array_key_exists('username', $data)) {
                $userUpdates['username'] = $data['username'];
            }
            if (array_key_exists('email', $data)) {
                $userUpdates['email'] = $data['email'];
            }
            if (!empty($data['password'])) {
                // NOTE: the User model casts 'password' => 'hashed', so the cast
                // hashes it automatically. Assign the RAW value here — calling
                // Hash::make() too would double-hash and break login.
                $userUpdates['password'] = $data['password'];
            }
            if (array_key_exists('roles', $data)) {
                // Multipart can deliver a single role as a string; normalize to array.
                $userUpdates['domain_role'] = is_array($data['roles'])
                    ? array_values($data['roles'])
                    : [$data['roles']];
            }

            // Replace avatar if a new image was uploaded.
            if (!empty($data['profile']) && $data['profile'] instanceof \Illuminate\Http\UploadedFile) {
                if ($user->avatar) {
                    Storage::disk('public')->delete(str_replace('/storage/', '', $user->avatar));
                }
                $path = $data['profile']->store('avatars', 'public');
                $userUpdates['avatar'] = '/storage/' . $path;
            }

            // Recompute display name when any name part changes.
            $detail = $user->employeeDetail;
            $firstName  = $data['first_name']  ?? $detail?->first_name;
            $middleName = $data['middle_name'] ?? $detail?->middle_name;
            $lastName   = $data['last_name']   ?? $detail?->last_name;

            if (
                array_key_exists('first_name', $data) ||
                array_key_exists('middle_name', $data) ||
                array_key_exists('last_name', $data)
            ) {
                $userUpdates['name'] = trim(
                    $firstName . ' ' .
                    (!empty($middleName) ? strtoupper(substr($middleName, 0, 1)) . '. ' : '') .
                    $lastName
                );
            }

            if (!empty($userUpdates)) {
                $user->update($userUpdates);
            }

            // --- employee_details table ---
            $detailFields = [
                'first_name', 'middle_name', 'last_name', 'contact_number',
                'gender', 'civil_status', 'birthdate', 'position',
                'department', 'pagibig', 'sss', 'philhealth',
            ];

            $detailUpdates = [];
            foreach ($detailFields as $field) {
                if (array_key_exists($field, $data)) {
                    $detailUpdates[$field] = $data[$field];
                }
            }

            // Append any newly uploaded additional files to the existing list.
            if (!empty($data['additionalFiles'])) {
                $existing = [];
                if ($detail && $detail->files) {
                    $existing = is_array($detail->files) ? $detail->files : (json_decode($detail->files, true) ?: []);
                }
                foreach ($data['additionalFiles'] as $file) {
                    if ($file instanceof \Illuminate\Http\UploadedFile) {
                        $path = $file->store('employee_files', 'public');
                        $existing[] = '/storage/' . $path;
                    }
                }
                $detailUpdates['files'] = !empty($existing) ? json_encode($existing) : null;
            }

            if (!empty($detailUpdates)) {
                if ($detail) {
                    // Row exists — a plain update can't violate NOT NULL on
                    // columns we're not touching.
                    $detail->update($detailUpdates);
                } else {
                    // No detail row yet (unexpected for an existing user, but be
                    // safe): fill required NOT NULL columns with sensible blanks.
                    EmployeeDetails::create(array_merge([
                        'user_id'        => $user->id,
                        'contact_number' => '',
                        'gender'         => $data['gender'] ?? 'other',
                        'civil_status'   => $data['civil_status'] ?? 'single',
                        'birthdate'      => $data['birthdate'] ?? now()->toDateString(),
                        'position'       => $data['position'] ?? '',
                        'department'     => $data['department'] ?? '',
                    ], $detailUpdates));
                }
            }

            // --- addresses (current + permanent) ---
            $this->upsertAddress($user->id, 'current', [
                'street'   => $data['currentStreet'] ?? null,
                'brangay'  => $data['currentBarangay'] ?? null,
                'city'     => $data['currentCity'] ?? null,
                'province' => $data['currentProvince'] ?? null,
                'postal'   => $data['currentPostalCode'] ?? null,
            ], $data, [
                'currentStreet', 'currentBarangay', 'currentCity',
                'currentProvince', 'currentPostalCode',
            ]);

            $this->upsertAddress($user->id, 'permanent', [
                'street'   => $data['permanentStreet'] ?? null,
                'brangay'  => $data['permanentBarangay'] ?? null,
                'city'     => $data['permanentCity'] ?? null,
                'province' => $data['permanentProvince'] ?? null,
                'postal'   => $data['permanentPostalCode'] ?? null,
            ], $data, [
                'permanentStreet', 'permanentBarangay', 'permanentCity',
                'permanentProvince', 'permanentPostalCode',
            ]);

            return $user->fresh(['addresses', 'employeeDetail']);
        });
    }

    /**
     * Update an address row only when at least one of its fields was submitted,
     * so an edit that doesn't touch addresses leaves them untouched.
     */
    private function upsertAddress(int $userId, string $type, array $values, array $data, array $keys): void
    {
        $touched = collect($keys)->some(fn ($k) => array_key_exists($k, $data));
        if (!$touched) {
            return;
        }

        UserAddress::updateOrCreate(
            ['user_id' => $userId, 'type' => $type],
            array_merge($values, ['country' => 'Philippines'])
        );
    }

    /**
     * Soft-delete (deactivate) an employee. Reversible via restore().
     */
    public function delete(int $id): bool
    {
        $user = User::whereJsonContains('domain_access', 'ash')->find($id);

        if (!$user) {
            return false;
        }

        return (bool) $user->delete();
    }

    /**
     * Restore a previously soft-deleted employee.
     */
    public function restore(int $id): ?User
    {
        $user = User::onlyTrashed()
            ->whereJsonContains('domain_access', 'ash')
            ->find($id);

        if (!$user) {
            return null;
        }

        $user->restore();

        return $user->fresh(['addresses', 'employeeDetail']);
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