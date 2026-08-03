<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\ForgotPasswordRequest;
use App\Http\Requests\Storefront\ResetPasswordRequest;
use App\Mail\Storefront\PasswordResetMail;
use App\Models\Storefront\Customer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

class PasswordResetController extends Controller
{
    /**
     * The customer broker, registered by StorefrontServiceProvider against the
     * storefront_users provider and the storefront_password_reset_tokens table.
     *
     * NOT the default broker: this application also hosts the ERP, whose default
     * broker resolves STAFF accounts out of `users` and writes to the staff
     * `password_reset_tokens` table. Reset a shopper's password through it and you
     * are either looking up the wrong account or minting a token that can be
     * redeemed against an employee login.
     */
    private const BROKER = 'storefront';

    /**
     * The same sentence whether or not the address is registered. Anything that
     * differs between the two branches — wording, status, or latency — turns this
     * endpoint into an account-enumeration oracle, which is why the copy is a
     * constant rather than something either branch composes.
     */
    private const NEUTRAL_REPLY = 'If that address has an account, a reset link is on its way.';

    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated()['email'];

        // Only the "account exists" branch does real work (write a token, render and
        // dispatch mail), so without a floor on the response time the clock answers
        // what the message won't. 200ms is what the framework's own broker uses.
        (new Timebox)->call(function () use ($email) {
            $user = Customer::where('email', $email)->first();

            if (! $user) {
                return;
            }

            // createToken rather than sendResetLink: the broker's flow would deliver
            // through the notification channel and hand back a status we would have
            // to throw away to stay neutral. It still deletes any token previously
            // issued for this address, so only the newest link is live.
            /** @var \Illuminate\Auth\Passwords\PasswordBroker $broker — createToken is on the class, not the contract. */
            $broker = Password::broker(self::BROKER);
            $token = $broker->createToken($user);

            Mail::to($user->email)->send(new PasswordResetMail($user, $token));
        }, 200_000);

        return response()->json(['message' => self::NEUTRAL_REPLY]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $resetUser = null;

        // reset() validates the token and deletes it in the same call, so a link
        // cannot be replayed — doing the check ourselves would open the window
        // between "valid" and "consumed" that this closes.
        $status = Password::broker(self::BROKER)->reset(
            $request->validated(),
            function (Customer $user, string $password) use (&$resetUser) {
                // 'password' carries a 'hashed' cast, so the plaintext is hashed on write.
                // setRememberToken returns void, hence the three statements.
                $user->forceFill(['password' => $password]);
                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));

                $resetUser = $user;
            },
        );

        // The broker contract's own constant, not Password::PASSWORD_RESET — the
        // value is identical, but reading it off the facade next to a broker('...')
        // call invites someone to "tidy" the two back onto the default broker.
        if ($status !== PasswordBroker::PASSWORD_RESET) {
            // One message for every failure. An unknown email, a forged token and an
            // expired one all read the same, for the same reason sendLink is neutral.
            // Shaped like a FormRequest failure so the SPA has one error path.
            return response()->json([
                'message' => 'That reset link is invalid or has expired.',
                'errors' => [
                    'token' => ['That reset link is invalid or has expired.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Password reset successful.',
            // Rotating the API token is the point of the exercise: whoever prompted
            // the reset may be holding a live bearer token, and changing the password
            // alone would leave it working. This also signs the real owner back in.
            'token' => $resetUser->issueApiToken(),
            'user' => [
                'id' => $resetUser->id,
                'name' => $resetUser->name,
                'email' => $resetUser->email,
            ],
        ]);
    }
}
