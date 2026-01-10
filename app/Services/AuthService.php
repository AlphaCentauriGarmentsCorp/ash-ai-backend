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
    public function register(array $data, array $domains, array $roles)
    {
        // Create user WITHOUT domain fields
        $user = User::create([
            'name'     => $data['name'] ?? null,
            'email'    => $data['email'],
            'password' => bcrypt($data['password']),
            'domain_access' =>  array_values($domains),
            'domain_role'   => array_values($roles),
        ]);

        // Clear permissions (safe)
        $user->syncPermissions([]);

        // Ensure roles exist (BACKEND roles only)
        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }

        // Assign roles using Spatie
        $user->syncRoles($roles); // better than assignRole for consistency

        // Generate OTP
        $otp = rand(100000, 999999);
        $user->otp = $otp;
        $user->otp_expires_at = Carbon::now()->addMinutes(5);
        $user->save();

        // Send OTP email
        Mail::raw(
            "Your OTP code is: {$otp}. It will expire in 5 minutes.",
            function ($message) use ($user) {
                $message->to($user->email)->subject('Your OTP Code');
            }
        );

        return $user;
    }


    // Login user
    public function login(array $data, string $frontend): array
    {
        $user = User::where('email', $data['email'])->first();

        if (
            ! $user ||
            ! Hash::check($data['password'], $user->password) ||
            ! is_array($user->domain_access) ||
            ! in_array($frontend, $user->domain_access, true)
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
        $remember = filter_var($data['remember'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Create token with domain scope (IMPORTANT)
        $expiration = $remember
            ? Carbon::now()->addDays(30)
            : Carbon::now()->addHours(1);

        $token = $user->createToken(
            'api_token',
            ['domain:' . $frontend],
            $expiration
        )->plainTextToken;

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    // Verify OTP after registration and issue token
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
