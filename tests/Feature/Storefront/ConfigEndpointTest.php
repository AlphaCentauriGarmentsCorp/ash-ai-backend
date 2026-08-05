<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** Every key in the payload, at any depth. */
    private function keysOf(array $payload, string $prefix = ''): array
    {
        $keys = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "$prefix.$key";
            $keys[] = $path;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->keysOf($value, $path));
            }
        }

        return $keys;
    }

    /**
     * The SPA reads this as res.data.data.google_client_id
     * (src/storefront/api/configApi.js in reefer-frontend),
     * so the 'data' envelope is part of the contract, not decoration — flatten it
     * and the button silently never renders.
     */
    public function test_it_publishes_the_google_client_id_to_anyone(): void
    {
        config()->set('services.google.client_id', 'test-client.apps.googleusercontent.com');

        // No bearer token: the SPA reads this before anyone has signed in.
        $this->getJson('/api/storefront/v1/config')
            ->assertStatus(200)
            ->assertJsonPath('data.google_client_id', 'test-client.apps.googleusercontent.com');
    }

    /**
     * The blank-credentials case is a supported state, not an error: /config reports
     * null and the SPA hides the button. It must not 500 on the way there.
     */
    public function test_an_unconfigured_google_client_id_is_reported_as_null(): void
    {
        config()->set('services.google.client_id', null);

        $response = $this->getJson('/api/storefront/v1/config')->assertStatus(200);

        $this->assertArrayHasKey('google_client_id', $response->json('data'));
        $this->assertNull($response->json('data.google_client_id'));
    }

    public function test_it_never_leaks_a_secret(): void
    {
        config()->set('services.google.client_id', 'test-client.apps.googleusercontent.com');
        config()->set('services.google.client_secret', 'GOCSPX-do-not-ship-this');
        config()->set('services.paymongo.secret', 'sk_test_do-not-ship-this-either');
        config()->set('services.paymongo.public', 'pk_test_this_one_is_public');

        // The integration reads the PayMongo secret from config/reefer.php, not from
        // the ERP's config/services.php — plant it there too, or this test would stop
        // covering the key that actually holds the live secret.
        config()->set('reefer.paymongo.secret', 'sk_test_reefer_key_do_not_ship');

        $response = $this->getJson('/api/storefront/v1/config')->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('GOCSPX-do-not-ship-this', $body);
        $this->assertStringNotContainsString('sk_test_do-not-ship-this-either', $body);
        $this->assertStringNotContainsString('sk_test_reefer_key_do_not_ship', $body);

        // Belt and braces: nothing that even calls itself a secret, whatever it holds.
        foreach ($this->keysOf($response->json() ?? []) as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/secret|private|api_key|password/i',
                $key,
                "GET /api/storefront/v1/config exposes a key named '$key'"
            );
        }
    }

    public function test_it_does_not_leak_the_app_key_or_database_credentials(): void
    {
        $body = $this->getJson('/api/storefront/v1/config')->assertStatus(200)->getContent();

        $secrets = array_filter([
            config('app.key'),
            config('database.connections.mysql.password'),
        ], fn ($value) => is_string($value) && $value !== '');

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
