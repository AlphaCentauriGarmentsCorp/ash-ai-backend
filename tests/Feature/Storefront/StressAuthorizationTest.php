<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Address;
use App\Models\Storefront\Order;
use App\Models\Storefront\Product;
use App\Models\Storefront\ProductReview;
use App\Models\Storefront\StockAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Object-level authorization (IDOR) stress pass on the storefront.
 *
 * Two real shoppers are built through the public API — Alice and Bob — each with an
 * address, a cart line, a placed + delivered order, a review, a favorite, a return
 * request and a stock alert. Bob then attacks every one of Alice's object ids, and an
 * anonymous client attacks them too.
 *
 * Throttling is switched off: 'throttle:storefront-api' is 60/minute per IP and every
 * request here comes from 127.0.0.1, so without this the matrix collapses into 429s
 * that prove nothing either way about authorization.
 */
class StressAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/storefront/v1';

    /** In stock, so it can be bought. */
    private const TEE = 'idor-tee';

    /** Permanently sold out, which is the only thing a stock alert may be opened on. */
    private const SOLD_OUT = 'idor-sold-out';

    /** @var array{token:string, email:string, address:int, address2:int, cart_item:int, order:string, review:int, alert:int, return:string} */
    private array $alice;

    /** @var array{token:string, email:string, address:int, address2:int, cart_item:int, order:string, review:int, alert:int, return:string} */
    private array $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->makeProduct(self::TEE, onHand: 50);
        $this->makeProduct(self::SOLD_OUT, onHand: 0);

        $this->alice = $this->makeShopper('Alice Wonderland', 'alice@idor.test', '09170000111');
        $this->bob = $this->makeShopper('Bob Bobson', 'bob@idor.test', '09280000222');
    }

    // =================================================================
    // Bob vs Alice — write paths
    // =================================================================

    public function test_bob_cannot_update_or_delete_alices_addresses(): void
    {
        foreach ([$this->alice['address'], $this->alice['address2']] as $id) {
            $this->withToken($this->bob['token'])
                ->patchJson(self::API."/addresses/{$id}", ['street' => 'PWNED BY BOB'])
                ->assertStatus(404);

            $this->withToken($this->bob['token'])
                ->deleteJson(self::API."/addresses/{$id}")
                ->assertStatus(404);
        }

        // Nothing moved, and nothing was removed.
        $this->assertSame(
            'Alice Wonderland Secret St',
            Address::find($this->alice['address'])?->street,
            "Bob rewrote Alice's address",
        );
        $this->assertNotNull(Address::find($this->alice['address2']), "Bob deleted Alice's address");
    }

    public function test_bob_cannot_touch_alices_cart_line_by_id_or_by_guessing_adjacent_ids(): void
    {
        $ids = range(max(1, $this->alice['cart_item'] - 2), $this->alice['cart_item'] + 2);

        foreach ($ids as $id) {
            if ($id === $this->bob['cart_item']) {
                continue; // his own line is legitimately his
            }

            $this->withToken($this->bob['token'])
                ->patchJson(self::API."/cart/items/{$id}", ['qty' => 99])
                ->assertStatus(404);
        }

        $this->withToken($this->bob['token'])
            ->deleteJson(self::API."/cart/items/{$this->alice['cart_item']}")
            ->assertStatus(404);

        $this->withToken($this->alice['token'])->getJson(self::API.'/cart')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $this->alice['cart_item'])
            ->assertJsonPath('data.items.0.qty', 3);
    }

    public function test_bob_cannot_advance_alices_order_along_the_tracker(): void
    {
        $before = Order::where('order_number', $this->alice['order'])->firstOrFail();

        foreach ([1, 2, 3, 4] as $stage) {
            $this->withToken($this->bob['token'])
                ->postJson(self::API."/orders/{$this->alice['order']}/advance", ['stage' => $stage])
                ->assertStatus(404);
        }

        $this->assertSame(
            (int) $before->stage,
            (int) Order::where('order_number', $this->alice['order'])->firstOrFail()->stage,
            "Bob moved Alice's order along the tracker",
        );
    }

    public function test_bob_cannot_open_a_return_against_alices_order(): void
    {
        $this->withToken($this->bob['token'])
            ->postJson(self::API."/orders/{$this->alice['order']}/returns", [
                'reason' => 'damaged',
                'items' => [['slug' => self::TEE, 'size' => 'M', 'qty' => 1]],
            ])
            ->assertStatus(404);
    }

    public function test_bob_cannot_read_or_cancel_alices_return(): void
    {
        $this->withToken($this->bob['token'])
            ->getJson(self::API."/returns/{$this->alice['return']}")
            ->assertStatus(404);

        $this->withToken($this->bob['token'])
            ->postJson(self::API."/returns/{$this->alice['return']}/cancel")
            ->assertStatus(404);

        // Still open, still Alice's.
        $this->withToken($this->alice['token'])
            ->getJson(self::API."/returns/{$this->alice['return']}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'requested');
    }

    public function test_bob_cannot_delete_alices_stock_alert(): void
    {
        foreach (range(max(1, $this->alice['alert'] - 1), $this->alice['alert'] + 1) as $id) {
            if ($id === $this->bob['alert']) {
                continue;
            }

            $this->withToken($this->bob['token'])
                ->deleteJson(self::API."/stock-alerts/{$id}")
                ->assertStatus(404);
        }

        $this->assertNotNull(StockAlert::find($this->alice['alert']), "Bob deleted Alice's stock alert");
    }

    public function test_bob_cannot_write_a_review_on_a_product_he_never_bought(): void
    {
        $this->withToken($this->bob['token'])
            ->postJson(self::API.'/products/'.self::SOLD_OUT.'/reviews', ['rating' => 1, 'body' => 'never bought this'])
            ->assertStatus(403);
    }

    public function test_bob_deleting_his_review_leaves_alices_review_alone(): void
    {
        $this->withToken($this->bob['token'])
            ->deleteJson(self::API.'/products/'.self::TEE.'/reviews/mine')
            ->assertStatus(200);

        $this->assertNotNull(
            ProductReview::find($this->alice['review']),
            "Bob's review delete took Alice's review with it",
        );
    }

    // =================================================================
    // Bob vs Alice — read paths and leakage
    // =================================================================

    public function test_no_list_endpoint_returns_another_customers_rows(): void
    {
        $secrets = [$this->alice['email'], 'Alice Wonderland Secret St', '09170000111'];

        foreach (['/addresses', '/orders', '/returns', '/stock-alerts', '/favorites', '/account', '/auth/me', '/cart'] as $path) {
            $body = $this->withToken($this->bob['token'])->getJson(self::API.$path)
                ->assertStatus(200)
                ->getContent();

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    (string) $body,
                    "GET {$path} leaked Alice's data to Bob",
                );
            }
        }
    }

    public function test_public_review_list_never_leaks_another_customers_email_or_user_id(): void
    {
        $this->flushHeaders(); // read it as a signed-out visitor, not as Bob

        $body = (string) $this->getJson(self::API.'/products/'.self::TEE.'/reviews')
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString($this->alice['email'], $body);
        $this->assertStringNotContainsString($this->bob['email'], $body);
        $this->assertStringNotContainsString('user_id', $body);
    }

    public function test_no_product_or_order_response_leaks_internal_warehouse_fields(): void
    {
        $internal = ['on_hand', 'allocated', 'shelf_location', 'warehouse', 'cost'];

        $bodies = [
            'catalogue' => (string) $this->getJson(self::API.'/products')->assertStatus(200)->getContent(),
            'product' => (string) $this->getJson(self::API.'/products/'.self::TEE)->assertStatus(200)->getContent(),
            'orders' => (string) $this->withToken($this->alice['token'])->getJson(self::API.'/orders')->assertStatus(200)->getContent(),
            'cart' => (string) $this->withToken($this->alice['token'])->getJson(self::API.'/cart')->assertStatus(200)->getContent(),
            'favorites' => (string) $this->withToken($this->alice['token'])->getJson(self::API.'/favorites')->assertStatus(200)->getContent(),
        ];

        foreach ($bodies as $label => $body) {
            foreach ($internal as $field) {
                $this->assertStringNotContainsString($field, $body, "{$label} response leaked the internal field {$field}");
            }
        }
    }

    // =================================================================
    // Anonymous
    // =================================================================

    public function test_an_unauthenticated_client_reaches_none_of_it(): void
    {
        // withToken() writes a DEFAULT header that survives every later request in the
        // same test, so the fixtures above leave Bob's bearer token attached. Without
        // this the "anonymous" probes below are quietly Bob and come back 200.
        $this->flushHeaders();

        $probes = [
            ['patchJson', "/addresses/{$this->alice['address']}", ['street' => 'anon']],
            ['deleteJson', "/addresses/{$this->alice['address']}", []],
            ['getJson', '/addresses', []],
            ['patchJson', "/cart/items/{$this->alice['cart_item']}", ['qty' => 1]],
            ['deleteJson', "/cart/items/{$this->alice['cart_item']}", []],
            ['getJson', '/cart', []],
            ['getJson', "/returns/{$this->alice['return']}", []],
            ['postJson', "/returns/{$this->alice['return']}/cancel", []],
            ['deleteJson', "/stock-alerts/{$this->alice['alert']}", []],
            ['getJson', '/stock-alerts', []],
            ['postJson', "/orders/{$this->alice['order']}/advance", ['stage' => 4]],
            ['getJson', '/orders', []],
            ['getJson', '/account', []],
            ['getJson', '/favorites', []],
            ['deleteJson', '/favorites/'.self::TEE, []],
            ['postJson', '/products/'.self::TEE.'/reviews', ['rating' => 1]],
            ['postJson', '/discounts/validate', ['code' => 'X']],
        ];

        $got = [];

        foreach ($probes as [$method, $path, $payload]) {
            $got[strtoupper(str_replace('Json', '', $method)).' '.$path] =
                $this->{$method}(self::API.$path, $payload)->getStatusCode();
        }

        // Nothing anonymous may ever come back 2xx. 401 is the only correct answer;
        // anything else (notably a 404 from route-model binding, which resolves
        // BEFORE the auth middleware) is reported by name.
        $this->assertSame(array_fill_keys(array_keys($got), 401), $got);
    }

    // =================================================================
    // FINDING: the 404 body is an existence oracle
    // =================================================================

    /**
     * Every owner check on a route-model-bound storefront resource is written as
     * `abort_unless($model->user_id === auth()->id(), 404)` — deliberately a 404, and
     * the code says so:
     *
     *   AddressController:  "404 rather than 403: a stranger's address id should not
     *                        be confirmable."
     *   StockAlertController: "404 rather than 403, matching AddressController: a
     *                        stranger's id stays unconfirmable."
     *   ReturnRequestController: "Someone else's return is a 404, exactly like someone
     *                        else's order."
     *
     * But route-model binding has ALREADY resolved the row by then, so the two 404s do
     * not look the same on the wire. A miss never reaches the controller: it is a
     * ModelNotFoundException, which Laravel converts to a NotFoundHttpException whose
     * message names the model and the key. abort(404) with no message produces an empty
     * one. The bodies differ, so the status code's anonymity is cosmetic.
     *
     * The two payloads seen against the running dev server (APP_DEBUG=false):
     *
     *   id belongs to someone else -> 404 {"message": ""}
     *   id does not exist          -> 404 {"message": "No query results for model
     *                                      [App\\Models\\Storefront\\Address] 9999"}
     *
     * The class name is disclosed too.
     */
    /**
     * The same oracle, but with NO ACCOUNT AT ALL.
     *
     * Laravel resolves route-model bindings in SubstituteBindings, which sits in the
     * framework's middleware priority list and is therefore hoisted ahead of the
     * storefront's own AuthenticateApiToken. So a bound id that exists gets as far as
     * the auth check and answers 401, while a bound id that does not exist dies in
     * binding and answers 404 — before anyone has proved who they are.
     *
     * Against the running dev server, holding no token whatsoever:
     *
     *   PATCH  /addresses/3            -> 401 {"message":"Unauthenticated."}
     *   PATCH  /addresses/9999         -> 404 {"message":"No query results for model
     *                                          [App\\Models\\Storefront\\Address] 9999"}
     *   GET    /returns/RET-000005     -> 401
     *   GET    /returns/RET-999999     -> 404
     *   DELETE /stock-alerts/3         -> 401
     *   DELETE /stock-alerts/9999      -> 404
     *
     * Return references are sequential (RET-000001, RET-000002, ...), so walking them
     * counts and confirms every return request in the shop. Address and alert ids are
     * autoincrement, so the same walk maps which customer rows exist. CartController
     * already documents this exact bug and dodges it by not binding at all; the four
     * bound resources below did not get the same treatment.
     *
     * This one does not depend on APP_DEBUG — the status code alone is the oracle.
     */
    public function test_an_anonymous_client_cannot_tell_a_real_object_id_from_a_made_up_one(): void
    {
        $this->flushHeaders(); // see the note in the unauthenticated test above

        $cases = [
            'address' => ['patchJson', "/addresses/{$this->alice['address']}", '/addresses/999999'],
            'stock alert' => ['deleteJson', "/stock-alerts/{$this->alice['alert']}", '/stock-alerts/999999'],
            'return request' => ['getJson', "/returns/{$this->alice['return']}", '/returns/RET-999999'],
            'favorite product' => ['deleteJson', '/favorites/'.self::TEE, '/favorites/no-such-product'],
        ];

        foreach ($cases as $label => [$method, $realPath, $fakePath]) {
            $real = $this->{$method}(self::API.$realPath)->getStatusCode();
            $fake = $this->{$method}(self::API.$fakePath)->getStatusCode();

            $this->assertSame(
                $fake,
                $real,
                "An anonymous client can tell a real {$label} id from a made-up one: "
                ."the real id answered {$real} and the fake id answered {$fake}.",
            );
        }
    }

    public function test_a_404_for_someone_elses_object_is_indistinguishable_from_a_404_for_a_missing_one(): void
    {
        $cases = [
            'address' => ['deleteJson', "/addresses/{$this->alice['address']}", '/addresses/999999'],
            'stock alert' => ['deleteJson', "/stock-alerts/{$this->alice['alert']}", '/stock-alerts/999999'],
            'return request' => ['getJson', "/returns/{$this->alice['return']}", '/returns/RET-999999'],
        ];

        foreach ($cases as $label => [$method, $strangersPath, $missingPath]) {
            $strangers = $this->withToken($this->bob['token'])->{$method}(self::API.$strangersPath);
            $missing = $this->withToken($this->bob['token'])->{$method}(self::API.$missingPath);

            $strangers->assertStatus(404);
            $missing->assertStatus(404);

            $this->assertSame(
                $missing->json('message'),
                $strangers->json('message'),
                "The 404 for another customer's {$label} is distinguishable from the 404 for one that does "
                ."not exist, so Bob can enumerate which ids are real. "
                ."stranger's => ".json_encode($strangers->json('message'))
                .", missing => ".json_encode($missing->json('message')),
            );
        }
    }

    /**
     * The two endpoints that resolve the row by hand instead of by route binding get
     * this right, which is what the bound ones are being measured against.
     */
    public function test_the_hand_resolved_endpoints_do_not_leak_existence(): void
    {
        // Cart lines: CartController::findOwnedItem looks the id up inside the caller's
        // own cart, so a stranger's line and a made-up one are the same query miss.
        $strangers = $this->withToken($this->bob['token'])->deleteJson(self::API."/cart/items/{$this->alice['cart_item']}");
        $missing = $this->withToken($this->bob['token'])->deleteJson(self::API.'/cart/items/999999');
        $this->assertSame($missing->json('message'), $strangers->json('message'), 'cart item 404s differ');

        // Order tracker: the 404 body is composed from the caller's own input.
        $strangers = $this->withToken($this->bob['token'])->postJson(self::API."/orders/{$this->alice['order']}/advance", ['stage' => 4]);
        $missing = $this->withToken($this->bob['token'])->postJson(self::API.'/orders/RFR-PH0000000/advance', ['stage' => 4]);
        $this->assertSame(
            str_replace($this->alice['order'], 'X', (string) $strangers->json('message')),
            str_replace('RFR-PH0000000', 'X', (string) $missing->json('message')),
            'order advance 404s differ',
        );

        // Opening a return: ownership is part of the lookup query.
        $strangers = $this->withToken($this->bob['token'])->postJson(self::API."/orders/{$this->alice['order']}/returns", [
            'reason' => 'damaged',
            'items' => [['slug' => self::TEE, 'size' => 'M', 'qty' => 1]],
        ]);
        $missing = $this->withToken($this->bob['token'])->postJson(self::API.'/orders/RFR-PH0000000/returns', [
            'reason' => 'damaged',
            'items' => [['slug' => self::TEE, 'size' => 'M', 'qty' => 1]],
        ]);
        $this->assertSame($missing->json('message'), $strangers->json('message'), 'order returns 404s differ');
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function makeProduct(string $slug, int $onHand): Product
    {
        $product = Product::create([
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'audience' => 'unisex',
            'type' => 'tee',
            'price' => 500,
            'is_active' => true,
            'sort' => 1,
        ]);

        $product->variants()->create([
            'size' => 'M',
            'sku' => strtoupper($slug).'-M',
            'on_hand' => $onHand,
            'shelf_location' => 'A-01-02',
            'warehouse' => 'Main',
        ]);

        return $product;
    }

    /**
     * A shopper with one of everything a customer can own, all created through the
     * public API rather than written straight into the tables.
     *
     * @return array{token:string, email:string, address:int, address2:int, cart_item:int, order:string, review:int, alert:int, return:string}
     */
    private function makeShopper(string $name, string $email, string $phone): array
    {
        $token = $this->postJson(self::API.'/auth/register', [
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
        ])->assertStatus(201)->json('token');

        $address = $this->withToken($token)->postJson(self::API.'/addresses', [
            'label' => 'Home',
            'name' => $name,
            'phone' => $phone,
            'street' => "{$name} Secret St",
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'postal' => '1000',
            'is_default_shipping' => true,
        ])->assertStatus(201)->json('data.id');

        $address2 = $this->withToken($token)->postJson(self::API.'/addresses', [
            'label' => 'Work',
            'name' => $name,
            'phone' => $phone,
            'street' => "{$name} Work Tower",
            'city' => 'Makati',
            'province' => 'Metro Manila',
        ])->assertStatus(201)->json('data.id');

        // Placed first: checkout empties the lines it bought, so the cart line below
        // has to be added afterwards to survive.
        $order = $this->withToken($token)->postJson(self::API.'/orders', [
            'email' => $email,
            'ship_to_name' => $name,
            'phone' => $phone,
            'street' => "{$name} Secret St",
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'shipping_method' => 'golocal',
            'payment_method' => 'gcash',
            'items' => [['slug' => self::TEE, 'size' => 'M', 'qty' => 1]],
        ])->assertStatus(201)->json('data.order_number');

        $this->withToken($token)->postJson(self::API."/orders/{$order}/advance", ['stage' => 4])->assertStatus(200);

        $return = $this->withToken($token)->postJson(self::API."/orders/{$order}/returns", [
            'reason' => 'wrong_size',
            'items' => [['slug' => self::TEE, 'size' => 'M', 'qty' => 1]],
        ])->assertStatus(201)->json('data.reference');

        $cartItem = $this->withToken($token)->postJson(self::API.'/cart/items', [
            'slug' => self::TEE,
            'size' => 'M',
            'qty' => 3,
        ])->assertStatus(201)->json('data.items.0.id');

        $this->withToken($token)->postJson(self::API.'/favorites/toggle', ['slug' => self::TEE])->assertStatus(200);

        $alert = $this->withToken($token)->postJson(self::API.'/stock-alerts', [
            'slug' => self::SOLD_OUT,
            'size' => 'M',
        ])->assertStatus(201)->json('data.id');

        $review = $this->withToken($token)->postJson(self::API.'/products/'.self::TEE.'/reviews', [
            'rating' => 5,
            'body' => "{$name} private review text",
        ])->assertStatus(201)->json('viewer.my_review.id');

        return compact('token', 'email', 'address', 'address2', 'cartItem', 'order', 'review', 'alert', 'return')
            + ['cart_item' => $cartItem];
    }
}
