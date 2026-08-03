<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The resend endpoint writes its cooldown stamp BEFORE it sends, so a delivery failure
 * used to throttle an account for a code that never arrived: the shopper got a 500, an
 * empty inbox, and a refusal to try again for the next minute. Rolling the stamp back on
 * failure is what makes the error recoverable instead of a lockout.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $email = 'verify@example.com'): string
    {
        return $this->postJson('/api/storefront/v1/auth/register', [
            'name' => 'Shopper',
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    public function test_a_send_failure_leaves_the_account_able_to_retry_immediately(): void
    {
        $token = $this->token();

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP is down'));

        $this->withToken($token)
            ->postJson('/api/storefront/v1/auth/email/send')
            ->assertStatus(503);

        // The stamp is what gates the retry — if the failed attempt left it set, the
        // next call answers 429 and the shopper is stuck behind a code they never got.
        $this->assertNull(
            Customer::where('email', 'verify@example.com')->first()->email_verification_sent_at,
            'A failed send must not leave the resend cooldown armed.',
        );
    }

    public function test_a_successful_send_does_arm_the_cooldown(): void
    {
        $token = $this->token();

        $this->withToken($token)
            ->postJson('/api/storefront/v1/auth/email/send')
            ->assertStatus(200);

        $user = Customer::where('email', 'verify@example.com')->first();

        $this->assertNotNull($user->email_verification_sent_at);
        $this->assertNotNull($user->email_verification_code);

        // And the cooldown is real, not just recorded.
        $this->withToken($token)
            ->postJson('/api/storefront/v1/auth/email/send')
            ->assertStatus(429);
    }
}
