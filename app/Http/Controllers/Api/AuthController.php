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

    public function registerReefer(RegisterRequest $request)
    {
        $user = $this->authService->register(
            $request->validated(),
            ['reefer'],
            ['customer']
        );

        return response()->json([
            'message' => 'Reefer registration successful. OTP sent.',
            'user' => new UserResource($user),
        ]);
    }

    public function registerSorbetes(RegisterRequest $request)
    {
        $user = $this->authService->register(
            $request->validated(),
            ['sorbetes'],
            ['customer']
        );

        return response()->json([
            'message' => 'Sorbetes registration successful. OTP sent.',
            'user' => new UserResource($user),
        ]);
    }

    public function loginSorbetes(LoginRequest $request)
    {
        $data = $this->authService->login($request->validated(), 'sorbetes');
        return response()->json([
            'user' => new UserResource($data['user']),
            'token' => $data['token']
        ]);
    }

    public function loginReefer(LoginRequest $request)
    {
        $data = $this->authService->login($request->validated(), 'reefer');

        return response()->json([
            'user' => new UserResource($data['user']),
            'token' => $data['token']
        ]);
    }

    public function loginAsh(LoginRequest $request)
    {
        $data = $this->authService->login($request->validated(), 'ash');

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
