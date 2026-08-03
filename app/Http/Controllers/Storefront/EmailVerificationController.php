<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Mail\Storefront\VerifyEmailMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Email verification by 6-digit code.
 *
 * This is the check that proves the address is real — TOTP enrolment does not, since
 * the server hands out the secret. Nothing enforces it yet: config
 * 'reefer.require_email_verification' is the switch, and it is off.
 */
class EmailVerificationController extends Controller
{
    /** Seconds between resends. Stops the endpoint being used as a mail cannon. */
    private const RESEND_COOLDOWN = 60;

    /** POST /auth/email/send — mail a fresh code. */
    public function send(): JsonResponse
    {
        $user = auth()->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This email address is already verified.',
            ], 409);
        }

        $sentAt = $user->email_verification_sent_at;

        // Throttled on the column rather than the rate limiter, so the cooldown
        // survives a restart and follows the account instead of the IP.
        if ($sentAt) {
            $nextAllowedAt = $sentAt->copy()->addSeconds(self::RESEND_COOLDOWN);

            if ($nextAllowedAt->isFuture()) {
                $retryAfter = max(1, (int) ceil(now()->diffInSeconds($nextAllowedAt, true)));

                return response()->json([
                    'message' => "A code was just sent. Try again in {$retryAfter} seconds.",
                    'retry_after' => $retryAfter,
                ], 429)->header('Retry-After', (string) $retryAfter);
            }
        }

        // random_int, not rand/mt_rand — a predictable code is a bypass.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'email_verification_code' => $code,
            'email_verification_sent_at' => now(),
        ])->save();

        Mail::to($user->email)->send(new VerifyEmailMail($user, $code));

        return response()->json([
            'message' => 'If that address can receive mail, a 6-digit code is on its way.',
        ]);
    }

    /** POST /auth/email/verify — redeem the code. */
    public function verify(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This email address is already verified.',
                'user' => $user->toAuthArray(),
            ]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $stored = $user->email_verification_code;
        $sentAt = $user->email_verification_sent_at;
        $ttl = (int) config('reefer.email_verification_ttl');

        $expired = ! $sentAt || $sentAt->copy()->addMinutes($ttl)->isPast();

        // hash_equals so the response time cannot be used to walk the code digit by
        // digit. Wrong and expired share one message on purpose — the difference is
        // only useful to someone guessing.
        if (! $stored || $expired || ! hash_equals($stored, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code is wrong or has expired. Request a new one.',
            ]);
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_sent_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Email verified.',
            'user' => $user->toAuthArray(),
        ]);
    }
}
