<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\LoginRequest;
use App\Http\Requests\Storefront\RegisterRequest;
use App\Models\Storefront\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Both writes in one transaction. Registering is the row and then the API
        // token, and a failure between them used to leave a committed account with no
        // token that its owner could never log into and never re-register, because
        // storefront_users.email is unique and the address was already spent. Either
        // the whole signup lands or none of it does.
        [$user, $token] = DB::transaction(function () use ($data) {
            // 'password' has a 'hashed' cast on the model, so it is hashed on write.
            $user = Customer::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

            return [$user, $user->issueApiToken()];
        });

        return response()->json([
            'message' => 'Registration successful.',
            'token' => $token,
            'user' => $user->toAuthArray(),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = Customer::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        // Issuing here rotates the token, so logging in elsewhere ends the previous
        // session. One token column per user is the constraint behind that.
        return response()->json([
            'message' => 'Login successful.',
            'token' => $user->issueApiToken(),
            'user' => $user->toAuthArray(),
        ]);
    }

    public function logout(): JsonResponse
    {
        auth()->user()->revokeApiToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'user' => $user->toAuthArray() + ['phone' => $user->phone],
        ]);
    }
}
