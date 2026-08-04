<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hostile / malformed input against the storefront write surface.
 *
 * The contract under test: every one of these requests must come back as a clean
 * 4xx. A 500 is a finding, and so is a silent accept.
 *
 * Two of the tests below FAIL against the current code. They are written as
 * assertions on the contract, not on the bug, so they go green the moment the
 * missing guard is added.
 */
class StressInputTest extends TestCase
{
    // Aliased so the guard below can run BEFORE the trait drops anything.
    // setUp() is too late: parent::setUp() -> setUpTraits() -> refreshDatabase().
    use RefreshDatabase {
        refreshDatabase as private traitRefreshDatabase;
    }

    private const BASE = '/api/storefront/v1';

    /** MySQL's default max_allowed_packet. Nothing a client types may exceed it. */
    private const PACKET_LIMIT = 1048576;

    /**
     * RefreshDatabase runs `migrate:fresh`, which DROPS EVERY TABLE on whatever
     * connection is live when the test boots. phpunit.xml pins that to sqlite
     * :memory:, but a stale bootstrap/cache/config.php silently defeats those
     * <env> entries — Laravel reads the cached file and never consults them — and
     * the suite then drops the development database instead. That is not
     * hypothetical: it happened while this file was being written, and it emptied
     * ash_ai_backend.
     *
     * So the connection is checked before anything drops. Refuse to run unless the
     * target is unmistakably disposable.
     */
    protected function refreshDatabase(): void
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $disposable = $connection === 'sqlite'
            || $database === ':memory:'
            || str_contains($database, 'test')
            || str_contains($database, 'stress');

        if (! $disposable) {
            $this->markTestSkipped(
                "REFUSING TO RUN: RefreshDatabase would drop every table in "
                ."[{$connection}:{$database}], which does not look like a throwaway "
                ."database. Run `php artisan config:clear` (a cached config file "
                ."overrides phpunit.xml's sqlite settings), or point DB_DATABASE at a "
                ."dedicated test schema, then try again."
            );
        }

        $this->traitRefreshDatabase();
    }

    private function product(string $slug = 'stress-tee', int $stock = 50): Product
    {
        $product = Product::create([
            'slug' => $slug,
            'name' => 'Stress Tee',
            'audience' => 'unisex',
            'type' => 'tee',
            'price' => 650,
            'is_active' => true,
            'sort' => 1,
        ]);

        $product->variants()->create([
            'size' => 'L',
            'sku' => strtoupper($slug).'-L',
            'on_hand' => $stock,
        ]);

        return $product;
    }

    private function register(string $name, string $email): string
    {
        return $this->postJson(self::BASE.'/auth/register', [
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    private function orderPayload(string $slug, array $over = []): array
    {
        return array_merge([
            'email' => 'buyer@example.com',
            'ship_to_name' => 'Buyer',
            'phone' => '09171234567',
            'street' => '1 Test St',
            'city' => 'Manila',
            'province' => 'NCR',
            'postal' => '1000',
            'shipping_method' => 'golocal',
            'payment_method' => 'cod',
            'items' => [['slug' => $slug, 'size' => 'L', 'qty' => 1]],
        ], $over);
    }

    /** A signed-out request. withToken() sets a persistent header, so flush it. */
    private function asGuest(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    // =================================================================
    // FINDING 1 — stored 4-byte character in a shopper's display name
    //             permanently 500s the PUBLIC product-reviews endpoint.
    //
    // ProductReviewResource::displayName() abbreviates the surname with
    //   strtoupper(substr(end($parts), 0, 1))
    // substr() is BYTE-based. When the last word of the name starts with a
    // multi-byte character (any emoji, or a surname beginning with Ñ / Á / …)
    // it slices one byte out of the middle of a UTF-8 sequence. The resulting
    // broken string goes into response()->json(), json_encode() refuses it, and
    // the request dies with InvalidArgumentException: "Malformed UTF-8
    // characters, possibly incorrectly encoded".
    // =================================================================

    public function test_emoji_in_a_reviewer_name_does_not_500_the_public_reviews_endpoint(): void
    {
        $product = $this->product();

        // "Stress <U+1F984>test" — two words; the last one starts with a 4-byte char.
        $token = $this->register("Stress \u{1F984}test", 'buyer@example.com');

        $this->withToken($token)
            ->postJson(self::BASE.'/orders', $this->orderPayload($product->slug))
            ->assertCreated();

        $this->withToken($token)
            ->postJson(self::BASE."/products/{$product->slug}/reviews", ['rating' => 5, 'body' => 'good'])
            ->assertCreated();

        // Anyone at all — this list is public and unauthenticated.
        $this->asGuest()
            ->getJson(self::BASE."/products/{$product->slug}/reviews")
            ->assertOk();
    }

    public function test_accented_surname_does_not_500_the_public_reviews_endpoint(): void
    {
        $product = $this->product('stress-tee-2');

        // Not an exotic edge case: a real Filipino/Spanish surname.
        $token = $this->register("Juan \u{00D1}oro", 'juan@example.com');

        $this->withToken($token)
            ->postJson(self::BASE.'/orders', $this->orderPayload($product->slug, ['email' => 'juan@example.com']))
            ->assertCreated();

        $this->withToken($token)
            ->postJson(self::BASE."/products/{$product->slug}/reviews", ['rating' => 4, 'body' => 'fine'])
            ->assertCreated();

        $this->asGuest()
            ->getJson(self::BASE."/products/{$product->slug}/reviews")
            ->assertOk();
    }

    public function test_writing_a_review_under_such_a_name_returns_a_success_status(): void
    {
        // The write itself commits and THEN the response blows up, so the caller is
        // told the request failed while the row is already in the database.
        $product = $this->product('stress-tee-3');
        $token = $this->register("Ann \u{1F600}bc", 'ann@example.com');

        $this->withToken($token)
            ->postJson(self::BASE.'/orders', $this->orderPayload($product->slug, ['email' => 'ann@example.com']))
            ->assertCreated();

        $response = $this->withToken($token)
            ->postJson(self::BASE."/products/{$product->slug}/reviews", ['rating' => 5, 'body' => 'x']);

        $response->assertCreated();

        // If the review landed, the shopper must not have been shown an error.
        $this->assertDatabaseCount('storefront_product_reviews', 1);
    }

    // =================================================================
    // FINDING 2 — `slug` / `email` carry no max: rule, so unbounded client
    //             input is handed straight to the database as a query
    //             parameter. Past MySQL's max_allowed_packet (1 MB by
    //             default) the driver drops the connection and the request
    //             500s. Every other string field in the storefront request
    //             classes is capped; these were missed.
    //
    // Asserted DB-agnostically: nothing a client sends may reach the database
    // as a binding larger than the packet limit. Under SQLite the oversized
    // value is harmless, but it still gets there — which is the defect.
    // =================================================================

    /** @return array<int, int> byte length of every binding sent while $work ran */
    private function bindingSizes(callable $work): array
    {
        $sizes = [];

        DB::listen(function ($query) use (&$sizes) {
            foreach ($query->bindings as $binding) {
                if (is_string($binding)) {
                    $sizes[] = strlen($binding);
                }
            }
        });

        $work();

        return $sizes;
    }

    public function test_an_oversized_slug_is_rejected_before_it_reaches_the_database(): void
    {
        $this->product();
        $token = $this->register('Buyer', 'buyer@example.com');

        $huge = str_repeat('a', 2_000_000);

        $sizes = $this->bindingSizes(function () use ($token, $huge) {
            $this->withToken($token)->postJson(self::BASE.'/cart/items', [
                'slug' => $huge,
                'size' => 'L',
                'qty' => 1,
            ]);
        });

        $this->assertLessThanOrEqual(
            self::PACKET_LIMIT,
            $sizes === [] ? 0 : max($sizes),
            'A 2 MB slug was sent to the database as a query binding. On MySQL this '
            .'exceeds max_allowed_packet and the request 500s with '
            .'"Got a packet bigger than \'max_allowed_packet\' bytes". '
            .'StoreCartItemRequest::rules() needs a max: on slug.'
        );
    }

    public function test_an_oversized_login_email_is_rejected_before_it_reaches_the_database(): void
    {
        // Unauthenticated: this is the cheapest reachable version of the same bug.
        $huge = str_repeat('a', 2_000_000).'@example.com';

        $sizes = $this->bindingSizes(function () use ($huge) {
            $this->postJson(self::BASE.'/auth/login', [
                'email' => $huge,
                'password' => 'password123',
            ]);
        });

        $this->assertLessThanOrEqual(
            self::PACKET_LIMIT,
            $sizes === [] ? 0 : max($sizes),
            'A 2 MB email was sent to the database as a query binding by an '
            .'UNAUTHENTICATED request. LoginRequest::rules() has no max: on email, '
            .'unlike every other storefront request class.'
        );
    }

    public function test_an_oversized_order_line_slug_is_rejected_before_it_reaches_the_database(): void
    {
        $this->product();
        $token = $this->register('Buyer', 'buyer@example.com');

        $huge = str_repeat('a', 2_000_000);

        $sizes = $this->bindingSizes(function () use ($token, $huge) {
            $this->withToken($token)
                ->postJson(self::BASE.'/orders', $this->orderPayload($huge));
        });

        $this->assertLessThanOrEqual(
            self::PACKET_LIMIT,
            $sizes === [] ? 0 : max($sizes),
            'StoreOrderRequest::rules() has no max: on items.*.slug.'
        );
    }

    // =================================================================
    // Guards that currently HOLD. Kept so a future refactor cannot quietly
    // undo them.
    // =================================================================

    public function test_the_server_ignores_client_supplied_prices_totals_and_statuses(): void
    {
        $product = $this->product();
        $token = $this->register('Buyer', 'buyer@example.com');

        $response = $this->withToken($token)->postJson(self::BASE.'/orders', $this->orderPayload($product->slug, [
            'items' => [['slug' => $product->slug, 'size' => 'L', 'qty' => 2]],
            'total' => 1,
            'subtotal' => 1,
            'discount_amount' => 99999,
            'shipping_fee' => -500,
            'payment_status' => 'paid',
            'status' => 'Delivered',
            'stage' => 4,
            'order_number' => 'HACKED-001',
            'payment_ref' => 'pwn',
            'user_id' => 999,
            'delivered_at' => '2020-01-01 00:00:00',
        ]))->assertCreated();

        $response->assertJsonPath('data.subtotal', 1300);
        $response->assertJsonPath('data.total', 1300);
        $response->assertJsonPath('data.discount_amount', 0);
        $response->assertJsonPath('data.status', 'Processing');
        $response->assertJsonPath('data.stage', 0);
        $response->assertJsonPath('data.payment_status', 'pending');
        $this->assertNotSame('HACKED-001', $response->json('data.order_number'));
    }

    public function test_privilege_fields_cannot_be_mass_assigned_at_registration(): void
    {
        $this->postJson(self::BASE.'/auth/register', [
            'name' => 'Priv Esc',
            'email' => 'priv@example.com',
            'password' => 'password123',
            'is_admin' => true,
            'role' => 'admin',
            'email_verified_at' => '2020-01-01 00:00:00',
            'api_token' => str_repeat('A', 40),
            'google_id' => '1234567890',
        ])->assertCreated()->assertJsonPath('user.email_verified', false);

        // The attacker-chosen token must not authenticate anything.
        $this->asGuest()
            ->withToken(str_repeat('A', 40))
            ->getJson(self::BASE.'/auth/me')
            ->assertUnauthorized();

        $this->assertDatabaseMissing('storefront_users', [
            'email' => 'priv@example.com',
            'google_id' => '1234567890',
        ]);
    }

    /** @return array<string, array{mixed}> */
    public static function hostileQuantities(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'absurd' => [999999999],
            'fractional' => [1.5],
            'array' => [[1, 2]],
            'object' => [['a' => 1]],
            'scientific' => [1e10],
            'sql' => ["1 OR 1=1"],
            'overflow' => ['9223372036854775808'],
        ];
    }

    /** @dataProvider hostileQuantities */
    public function test_hostile_quantities_are_422_never_500(mixed $qty): void
    {
        $product = $this->product();
        $token = $this->register('Buyer', 'buyer@example.com');

        $this->withToken($token)->postJson(self::BASE.'/cart/items', [
            'slug' => $product->slug,
            'size' => 'L',
            'qty' => $qty,
        ])->assertStatus(422);
    }

    /** @return array<string, array{string}> */
    public static function hostileCatalogueQueries(): array
    {
        return [
            'sqli or' => ["search=' OR 1=1--"],
            'sqli union' => ["search=x' UNION SELECT password FROM storefront_users--"],
            'sqli drop' => ['search=%27%3B+DROP+TABLE+storefront_products%3B--'],
            'like wildcard' => ['search=%25'],
            'like underscore' => ['search=_'],
            'sort injection' => ['sort=price_asc,(select 1)'],
            'audience injection' => ["audience=men') OR (1=1"],
            'tag injection' => ["tag=NEW' OR '1'='1"],
            'array filter' => ['tag[]=NEW'],
            'per_page huge' => ['per_page=999999'],
            'per_page zero' => ['per_page=0'],
            'page negative' => ['page=-1'],
            'page huge' => ['page=99999999999999999999'],
            'page array' => ['page[]=1'],
        ];
    }

    /** @dataProvider hostileCatalogueQueries */
    public function test_catalogue_filters_never_500_and_never_dump_the_table(string $query): void
    {
        $this->product();
        $this->product('stress-tee-2');

        $response = $this->asGuest()->getJson(self::BASE.'/products?'.$query);

        $this->assertContains(
            $response->status(),
            [200, 422],
            "GET /products?{$query} answered {$response->status()}"
        );

        if ($response->status() === 200) {
            // Whatever came back, it is bounded by the per_page cap.
            $this->assertLessThanOrEqual(60, count($response->json('data') ?? []));
        }
    }

    public function test_malformed_and_wrongly_typed_bodies_are_422_never_500(): void
    {
        $this->product();
        $token = $this->register('Buyer', 'buyer@example.com');

        $bodies = ['{{{{', '[1,2,3]', '"hello"', 'null', ''];

        foreach ($bodies as $body) {
            $response = $this->call(
                'POST',
                self::BASE.'/cart/items',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                ],
                $body,
            );

            $this->assertSame(
                422,
                $response->status(),
                'Body '.var_export($body, true).' answered '.$response->status()
            );
        }
    }

    public function test_crlf_and_null_bytes_in_an_email_are_refused(): void
    {
        foreach (["victim@example.com\r\nBcc: attacker@evil.test", "victim@example.com\x00.evil.test"] as $email) {
            $this->postJson(self::BASE.'/auth/forgot-password', ['email' => $email])
                ->assertStatus(422)
                ->assertJsonValidationErrors('email');
        }
    }
}
