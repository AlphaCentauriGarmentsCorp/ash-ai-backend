<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    // Register user
    public function register(array $data)
    {
        $user = User::create([
            
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // auto-hashed
            'domain_role' => $data['domain_role'],
        ]);

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
                'email' => ['The provided credentials are incorrect.'],
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
