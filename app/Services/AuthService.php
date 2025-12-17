<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AuthService
{
    // Register user
    public function register(array $data)
    {
        $user = User::create($data);

        // Clear old permissions just in case
        $user->syncPermissions([]);
        
        // Ensure roles exist before assignment
        foreach ($data['domain_role'] ?? [] as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }
        $user->assignRole($data['domain_role'] ?? []);

        $token = $user->createToken('api_token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token
        ];
    }

    // Login user
    public function login(array $data)
    {
        $user = User::where('email', $data['email'])->first();


        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'message' => ['The provided credentials are incorrect.'],
            ]);
        }
        // FRONTEND ACCESS CHECK
        if (
            empty($data['frontend']) ||
            ! in_array($data['frontend'], $user->domain_access ?? [])
        ) {
            throw ValidationException::withMessages([
                'frontend' => ['You are not allowed to access this application'],
            ]);
        } 
        
        $token = $user->createToken('api_token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token
        ];
    }

    // Logout user
    public function logout($user)
    {
        $user->currentAccessToken()->delete();
    }
}
