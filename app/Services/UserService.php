<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserService
{
    /**
     * Get all User Brand.
     */
    public function getAll(): Collection
    {
        return User::all();
    }

    /**
     * Find a User Brand. by ID.
     */
    public function find(int $id): ?User
    {
        return User::find($id);
    }

    /**
     * Create a new User Brand..
     */
    public function create(array $data): User
    {
        return User::create($data);
    }

    /**
     * Update an existing User Brand..
     */
    public function update(int $id, array $data): ? User
    {
        $user = User::find($id);

        if (! $user) {
            return null;
        }

        $user->update($data);

        return $user;
    }

    /**
     * Delete a User Brand..
     */
    public function delete(int $id): bool
    {
        $user = User::find($id);

        if (! $user) {
            return false;
        }

        return $user->delete();
    }
}
