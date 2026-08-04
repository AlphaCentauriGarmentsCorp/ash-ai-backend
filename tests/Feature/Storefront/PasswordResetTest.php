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

    /**
     * The link must point at the STOREFRONT, not at this API.
     *
     * /reset-password is a client-side React route. Built from app.url it resolves to the
     * API host, whose routes/web.php serves only '/', so the shopper gets a 404 and the
     * token — single-use, and unpasteable because the SPA has no manual-entry screen —
     * expires unredeemed. Account recovery would be impossible for every user, and the
     * endpoint would still answer a cheerful "a reset link is on its way".
     */
    public function test_the_link_points_at_the_storefront_when_the_spa_is_on_its_own_domain(): void
    {
        config([
            'app.url' => 'https://api.sorbetesapparel.com',
            'reefer.spa_url' => 'https://reeferclothing.com',
        ]);

        $html = (new PasswordResetMail($this->customer(), 'tok-123'))->render();

        $this->assertStringContainsString('https://reeferclothing.com/reset-password?', $html);
        $this->assertStringNotContainsString('api.sorbetesapparel.com/reset-password', $html);
    }

    /** And the single-origin case is unchanged, since spa_url defaults to app.url. */
    public function test_the_link_still_uses_app_url_when_the_two_share_an_origin(): void
    {
        config([
            'app.url' => 'https://shop.example.com',
            'reefer.spa_url' => 'https://shop.example.com',
        ]);

        $this->assertStringContainsString(
            'https://shop.example.com/reset-password?',
            (new PasswordResetMail($this->customer(), 'tok-123'))->render(),
        );
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

    /**
     * The same property, but with the mailer failing rather than the view being wrong.
     *
     * Only the "account exists" branch sends anything, so an unguarded send makes the
     * MAILER the oracle: unknown address → 200, registered address → 500. It needs no
     * attacker skill to trigger, only a rate-limited provider or a wrong SMTP password
     * on the day, and the endpoint silently loses the property it is built around while
     * looking like an ordinary outage.
     */
    public function test_a_delivery_failure_still_reveals_nothing(): void
    {
        $user = $this->customer();

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP is down'));

        $registered = $this->postJson('/api/storefront/v1/auth/forgot-password', ['email' => $user->email]);
        $unregistered = $this->postJson('/api/storefront/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

        $this->assertSame(200, $registered->getStatusCode(), 'A broken mailer must not surface as a 500.');
        $this->assertSame($registered->getStatusCode(), $unregistered->getStatusCode());
        $this->assertSame($registered->json('message'), $unregistered->json('message'));
    }
}
