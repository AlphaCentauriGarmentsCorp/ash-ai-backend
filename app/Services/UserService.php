<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function list()
    {
        return User::all();
    }


    public function create(array $data)
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // auto-hashed
            'domain_role' => $data['domain_role'],
            'domain_access' => $data['domain_access'],
       ]);

        return $user;

    }

    public function update(User $user, array $data)
    {
        $user->update($data);
        return $user;
    }

    public function destroy(User $user)
    {
        // $user->delete();
        // return response()->json(['message' => 'Deleted successfully']);
    }
}