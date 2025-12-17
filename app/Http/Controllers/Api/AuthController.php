<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function register(RegisterRequest $request)
    {
        $user = $this->authService->register($request->validated());

        return response()->json([
            'user' => new UserResource($user),
            'message' => 'Registration successful. OTP sent to your email.',
        ]);

        // return response()->json([
        //     'user' => new UserResource($data['user']),
        //     'token' => $data['token']
        // ]);

    }

    public function login(LoginRequest $request)
    {
        $data = $this->authService->login($request->validated());

        return response()->json([
            'user' => new UserResource($data['user']),
            'token' => $data['token']
        ]);
    }

    // OTP verification (after registration)
    public function verifyOtp(Request $request)
    {
        $data = $this->authService->verifyOtp($request->only(['email', 'otp']));

        return response()->json([
            'user' => new UserResource($data['user']),
            'token' => $data['token'],
            'token_type' => 'Bearer',
            'message' => 'OTP verified successfully. You are now logged in.'
        ]);
    }

    public function logout(Request $request)
    {
        $this->authService->logout($request->user());

        return response()->json(['message' => 'Logged out successfully']);
    }
}
