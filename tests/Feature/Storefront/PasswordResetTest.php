<?php

namespace Tests\Feature\Storefront;

use App\Mail\Storefront\PasswordResetMail;
use App\Models\Storefront\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Covers the forgot-password path, which neither this project nor the source had a
 * single test for — which is how PasswordResetMail shipped pointing at
 * 'emails.password-reset' after the port relocated the templates to
 * resources/views/storefront/emails/. Every registered shopper's request 500'd.
 *
 * The important detail is that a Mail::fake()-only test would NOT have caught it:
 * faking swaps the mailer out before the view is ever rendered, so a wrong view path
 * stays invisible. Hence test_the_reset_mailable_actually_renders below, which builds
 * the Mailable and calls render() directly, and test_a_registered_address_gets_a_link
 * which runs the real endpoint with MAIL_MAILER=array (phpunit.xml) so the mailable is
 * genuinely rendered on the way into the array transport.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $email = 'shopper@example.com'): Customer
    {
        return Customer::create([
            'name' => 'Shopper',
            'email' => $email,
            'password' => 'password123',
        ]);
    }

    /**
     * The regression test proper. Mail::fake() is deliberately NOT used.
     */
    public function test_the_reset_mailable_actually_renders(): void
    {
        $html = (new PasswordResetMail($this->customer(), 'raw-token-abc'))->render();

        $this->assertStringContainsString('reset-password', $html);
        $this->assertStringContainsString('raw-token-abc', $html);
    }

    public function test_a_registered_address_gets_a_link_and_a_neutral_200(): void
    {
        $user = $this->customer();

        $response = $this->postJson('/api/storefront/v1/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertStatus(200);

        // The token is issued against the storefront broker's own table, never the
        // ERP's password_reset_tokens.
        $this->assertDatabaseHas('storefront_password_reset_tokens', ['email' => $user->email]);
    }

    /**
     * The anti-enumeration property the controller's NEUTRAL_REPLY and Timebox exist to
     * provide: an address with no account must be indistinguishable from one with an
     * account. It was the view-path bug that broke this — a registered address returned
     * 500 while an unregistered one returned 200, so the status code alone leaked which
     * emails were real.
     */
    public function test_an_unregistered_address_is_indistinguishable_from_a_registered_one(): void
    {
        Mail::fake();

        $user = $this->customer();

        $registered = $this->postJson('/api/storefront/v1/auth/forgot-password', ['email' => $user->email]);
        $unregistered = $this->postJson('/api/storefront/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

        $this->assertSame($registered->getStatusCode(), $unregistered->getStatusCode());
        $this->assertSame($registered->json('message'), $unregistered->json('message'));
    }
}
