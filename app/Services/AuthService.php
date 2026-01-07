<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Carbon\Carbon;
use App\Mail\OtpMail;
use Illuminate\Http\Exceptions\HttpResponseException;


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

        // Generate OTP
        $otp = rand(100000, 999999);
        $user->otp = $otp;
        $user->otp_expires_at = Carbon::now()->addMinutes(5);
        $user->save();

        Mail::raw("Your OTP code is: {$otp}. It will expire in 5 minutes.", function ($message) use ($user) {
            $message->to($user->email)
                ->subject('Your OTP Code');
        });

        // // Send OTP via email
        // Mail::to($user->email)->send(new OtpMail($otp));

        return $user;
    }

    // Login user
    public function login(array $data)
    {
        $user = User::where('email', $data['email'])->first();


        if (
            ! $user ||
            ! Hash::check($data['password'], $user->password) ||
            empty($data['frontend']) ||
            ! in_array($data['frontend'], $user->domain_access ?? [])
        ) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Validation failed',
                    'errors' => [
                        'email' => 'The provided credentials are incorrect.',
                    ],
                ], 422)
            );
        }


        $token = $user->createToken('api_token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token
        ];
    }

    /**
     * Verify OTP after registration and issue token
     */
    public function verifyOtp(array $data): array
    {
        $user = User::where('email', $data['email'])->firstOrFail();

        if ($user->otp !== $data['otp']) {
            throw new \Exception('Invalid OTP.');
        }

        if (Carbon::now()->greaterThan($user->otp_expires_at)) {
            throw new \Exception('OTP expired.');
        }

        // OTP verified → clear OTP fields
        // $user->otp = null;
        // $user->otp_expires_at = null;
        $user->last_verified = Carbon::now();
        $user->save();

        // Create Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

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
