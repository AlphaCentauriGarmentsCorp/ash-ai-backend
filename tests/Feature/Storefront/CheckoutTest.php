<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Order;
use App\Models\Storefront\Product;
use App\Models\Storefront\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(int $stock = 10, bool $active = true, string $slug = 'test-tee'): Product
    {
        $product = Product::create([
            'slug' => $slug,
            'name' => 'Test Tee',
            'audience' => 'men',
            'type' => 'tee',
            'price' => 1200,
            'is_active' => $active,
            'sort' => 1,
        ]);

        $product->variants()->create(['size' => 'M', 'sku' => strtoupper($slug).'-M', 'on_hand' => $stock]);

        return $product;
    }

    private function token(string $email = 'buyer@example.com'): string
    {
        return $this->postJson('/api/storefront/v1/auth/register', [
            'name' => 'Buyer',
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'buyer@example.com',
            'ship_to_name' => 'Buyer',
            'phone' => '09170000001',
            'street' => '123 Main St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'shipping_method' => 'golocal',
            'payment_method' => 'gcash',
            'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]],
        ], $overrides);
    }

    /**
     * An order reserves stock, it does not consume it. on_hand is what is physically
     * in the warehouse and only the ERP moves that; ordering raises `allocated`, and
     * `available` (on_hand - allocated) is what falls.
     */
    public function test_placing_an_order_allocates_stock_without_touching_on_hand(): void
    {
        $this->makeProduct(stock: 10);

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders', $this->payload())
            ->assertStatus(201);

        $variant = ProductVariant::first();

        $this->assertSame(10, $variant->on_hand, 'an order must not move the warehouse count');
        $this->assertSame(2, $variant->allocated, 'the ordered units must be reserved');
        $this->assertSame(8, $variant->available, 'available is what the shopper sees fall');
    }

    public function test_ordering_more_than_stock_is_rejected(): void
    {
        $this->makeProduct(stock: 1);

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders', $this->payload(['items' => [
                ['slug' => 'test-tee', 'size' => 'M', 'qty' => 5],
            ]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.qty');

        $variant = ProductVariant::first();
        $this->assertSame(1, $variant->on_hand, 'stock must not move on a rejected order');
        $this->assertSame(0, $variant->allocated, 'a rejected order must reserve nothing');
        $this->assertSame(0, Order::count());
    }

    public function test_declined_payment_creates_no_order_and_moves_no_stock(): void
    {
        $this->makeProduct(stock: 10);

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders', $this->payload(['simulate' => 'fail']))
            ->assertStatus(402);

        $this->assertSame(0, Order::count(), 'a declined payment must not leave an order behind');
        $variant = ProductVariant::first();
        $this->assertSame(10, $variant->on_hand);
        $this->assertSame(0, $variant->allocated, 'a declined payment must reserve nothing');
    }

    public function test_cash_on_delivery_stays_pending_with_no_payment_reference(): void
    {
        $this->makeProduct();

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders', $this->payload(['payment_method' => 'cod']))
            ->assertStatus(201)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.payment_ref', null);
    }

    public function test_an_online_payment_records_a_gateway_reference(): void
    {
        $this->makeProduct();

        $ref = $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders', $this->payload(['payment_method' => 'gcash']))
            ->assertStatus(201)
            ->assertJsonPath('data.payment_status', 'paid')
            ->json('data.payment_ref');

        // payment_ref was a permanently-null column before the gateway existed.
        $this->assertNotNull($ref);
        $this->assertStringStartsWith('SIM-', $ref);
    }

    public function test_deactivated_product_cannot_be_ordered(): void
    {
        $this->makeProduct(active: false);

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.slug');

        $this->assertSame(0, Order::count());
    }

    public function test_unavailable_size_is_a_validation_error_not_a_404(): void
    {
        $this->makeProduct();

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders', $this->payload(['items' => [
                ['slug' => 'test-tee', 'size' => 'XXL', 'qty' => 1],
            ]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.size');
    }

    /** Pull the numeric sequence out of an RFR-PH0019005-style order number. */
    private function seq(string $orderNumber): int
    {
        return (int) substr($orderNumber, strlen((string) config('reefer.order_prefix')));
    }

    public function test_order_numbers_are_unique_and_survive_a_deletion(): void
    {
        $this->makeProduct(stock: 50);
        $token = $this->token();

        $first = $this->withToken($token)->postJson('/api/storefront/v1/orders', $this->payload())->json('data.order_number');
        $second = $this->withToken($token)->postJson('/api/storefront/v1/orders', $this->payload())->json('data.order_number');

        $this->assertMatchesRegularExpression('/^RFR-PH\d{7}$/', $first);
        $this->assertSame(1, $this->seq($second) - $this->seq($first), 'numbers should advance by one');

        // Under the old COUNT(*) scheme, deleting an order made the next number
        // collide with one already issued.
        Order::where('order_number', $second)->delete();

        $third = $this->withToken($token)->postJson('/api/storefront/v1/orders', $this->payload())->json('data.order_number');

        $this->assertNotSame($first, $third);
        $this->assertNotSame($second, $third, 'a deleted order number must never be reissued');
        $this->assertGreaterThan($this->seq($second), $this->seq($third));
    }

    public function test_order_number_is_derived_from_the_configured_start(): void
    {
        $this->makeProduct(stock: 50);

        $number = $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders', $this->payload())
            ->json('data.order_number');

        // Asserted against the row's real id rather than a hardcoded 'RFR-PH0019005':
        // InnoDB does not roll back AUTO_INCREMENT, so the id a test sees depends on
        // how many orders earlier tests created. The formula is what matters.
        $order = Order::firstWhere('order_number', $number);
        $expected = config('reefer.order_prefix')
            . str_pad((string) (config('reefer.order_seq_start') + $order->id - 1), 7, '0', STR_PAD_LEFT);

        $this->assertSame($expected, $number);
    }

    public function test_price_and_total_are_computed_server_side(): void
    {
        $this->makeProduct(stock: 10);

        // Client-supplied money fields must be ignored entirely.
        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders', $this->payload([
                'subtotal' => 1,
                'total' => 1,
                'shipping_fee' => 0,
                'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 1, 'unit_price' => 1]],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.subtotal', 1200)
            ->assertJsonPath('data.total', 1200);
    }

    public function test_express_shipping_is_free_over_the_threshold(): void
    {
        $this->makeProduct(stock: 50);
        $token = $this->token();

        // 1 x 1200 is under the 2500 threshold -> 120 fee.
        $this->withToken($token)
            ->postJson('/api/storefront/v1/orders', $this->payload([
                'shipping_method' => 'express',
                'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 1]],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.shipping_fee', 120);

        // 3 x 1200 clears it -> free.
        $this->withToken($token)
            ->postJson('/api/storefront/v1/orders', $this->payload([
                'shipping_method' => 'express',
                'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 3]],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.shipping_fee', 0);
    }
}
