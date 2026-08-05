<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email = 'sec@example.com'): string
    {
        return $this->postJson('/api/storefront/v1/auth/register', [
            'name' => 'Sec User',
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    /**
     * The old static-file route concatenated the URL straight into a filesystem path,
     * so /..%2f..%2f.env served the app's real .env — APP_KEY included. That route is
     * gone and stayed gone through the merge: the storefront's SPA catch-all was NOT
     * ported (routes/web.php belongs to the ERP), so nothing in this application maps
     * a request path onto a filename any more. These probes now simply do not match a
     * route, and Laravel answers 404.
     *
     * The status code was never the point, and asserting on the BODY alone would be
     * vacuous if a file response ever came back (a file/stream response reads as ''
     * through the test client, so "does not contain APP_KEY" would pass without
     * proving anything). The BinaryFileResponse branch is kept for exactly that case:
     * if a future deploy drops an SPA build into public/ and reinstates a catch-all,
     * this still asserts the only file a probe may ever resolve to is that shell.
     *
     * What must never happen is a probe reaching a file outside public/, and that is
     * what is asserted.
     */
    public function test_traversal_probes_cannot_read_files_outside_the_public_directory(): void
    {
        $shell = realpath(public_path('index.html'));

        foreach (['/..%2f..%2f.env', '/%2e%2e%2f%2e%2e%2f.env', '/../.env', '/..%5c..%5c.env'] as $probe) {
            $served = $this->get($probe)->baseResponse;

            if ($served instanceof BinaryFileResponse) {
                $this->assertSame(
                    $shell,
                    realpath($served->getFile()->getPathname()),
                    "traversal probe $probe resolved to a file other than the SPA shell"
                );
            }

            $this->assertStringNotContainsString('APP_KEY', (string) $served->getContent());
        }

        // ...and an ordinary path is still answered by the app rather than blowing up,
        // so the router has not been narrowed into uselessness to make the above pass.
        // '/' is the ERP's own root route, which the storefront port does not touch.
        $this->get('/')->assertStatus(200);
    }

    public function test_token_is_not_stored_in_plaintext(): void
    {
        $token = $this->register();

        $stored = Customer::first()->api_token;

        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha256', $token), $stored);
    }

    public function test_api_token_is_never_exposed_in_responses(): void
    {
        $token = $this->register();

        $this->withToken($token)->getJson('/api/storefront/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonMissingPath('user.api_token')
            ->assertJsonMissingPath('user.password');
    }

    public function test_invalid_and_missing_tokens_are_rejected(): void
    {
        $this->register();

        $this->getJson('/api/storefront/v1/auth/me')->assertStatus(401);
        $this->withToken('not-a-real-token')->getJson('/api/storefront/v1/auth/me')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer '])->getJson('/api/storefront/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->register();

        $this->withToken($token)->getJson('/api/storefront/v1/auth/me')->assertStatus(200);
        $this->withToken($token)->postJson('/api/storefront/v1/auth/logout')->assertStatus(200);
        $this->withToken($token)->getJson('/api/storefront/v1/auth/me')->assertStatus(401);
    }

    public function test_api_token_cannot_be_mass_assigned(): void
    {
        $user = Customer::create([
            'name' => 'Mass',
            'email' => 'mass@example.com',
            'password' => 'password123',
            'api_token' => hash('sha256', 'attacker-chosen-token'),
        ]);

        $this->assertNull($user->fresh()->api_token);
    }

    public function test_login_is_rate_limited(): void
    {
        // register() shares the email+IP throttle bucket, so it spends one of the
        // five attempts before the first login is even tried.
        $this->register('victim@example.com');

        $attempt = fn () => $this->postJson('/api/storefront/v1/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong-password',
        ]);

        for ($i = 0; $i < 4; $i++) {
            $attempt()->assertStatus(401);
        }

        $attempt()->assertStatus(429);
    }

    public function test_a_correct_password_still_works_within_the_limit(): void
    {
        $this->register('ok@example.com');

        $this->postJson('/api/storefront/v1/auth/login', [
            'email' => 'ok@example.com',
            'password' => 'password123',
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_cors_never_allows_a_wildcard_origin(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://evil.example'])
            ->getJson('/api/storefront/v1/health');

        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    /**
     * Renamed from ..._without_credentials on the merge. config/cors.php now belongs
     * to the ERP, which sets supports_credentials => true for its own Sanctum session
     * flow, so Access-Control-Allow-Credentials IS present here — the storefront half
     * does not get to assert it away, and this test must not silently encode the
     * storefront's old bearer-token-only policy as if it still held.
     *
     * The dangerous combination is a WILDCARD origin plus credentials, and that is
     * what is asserted against: the header must echo the one specific allowed origin,
     * never '*', and the response must Vary on Origin so a proxy cannot serve one
     * origin's CORS headers to another.
     */
    public function test_cors_echoes_only_the_specific_allowed_origin(): void
    {
        $origin = config('cors.allowed_origins')[0];

        $response = $this->withHeaders(['Origin' => $origin])->getJson('/api/storefront/v1/health');

        $this->assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function test_orders_are_scoped_to_the_authenticated_user(): void
    {
        $alice = $this->register('alice@example.com');
        $bob = $this->register('bob@example.com');

        $this->withToken($alice)->getJson('/api/storefront/v1/orders')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->withToken($bob)->getJson('/api/storefront/v1/orders')->assertStatus(200)->assertJsonCount(0, 'data');
    }
}
