<?php

namespace Tests\Feature\Storefront;

use App\Mail\Storefront\ReturnRequestedMail;
use App\Models\Storefront\Order;
use App\Models\Storefront\Product;
use App\Models\Storefront\ReturnRequest;
use App\Models\Storefront\ReturnRequestItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReturnRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $slug = 'test-tee', int $price = 1200): Product
    {
        $product = Product::create([
            'slug' => $slug,
            'name' => 'Test Tee',
            'audience' => 'unisex',
            'type' => 'tee',
            'price' => $price,
            'is_active' => true,
            'sort' => 1,
        ]);

        $product->variants()->create(['size' => 'M', 'sku' => strtoupper($slug).'-M', 'on_hand' => 20]);
        $product->variants()->create(['size' => 'L', 'sku' => strtoupper($slug).'-L', 'on_hand' => 20]);

        return $product;
    }

    private function token(string $email = 'buyer@example.com', string $name = 'Juan dela Cruz'): string
    {
        return $this->postJson('/api/storefront/v1/auth/register', [
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    /** Buy through the real checkout, not by faking an order row. */
    private function buy(string $token, array $items = [['slug' => 'test-tee', 'size' => 'M', 'qty' => 1]], string $email = 'buyer@example.com'): string
    {
        return $this->withToken($token)->postJson('/api/storefront/v1/orders', [
            'email' => $email,
            'ship_to_name' => 'Juan dela Cruz',
            'phone' => '09170000001',
            'street' => '1 Main St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'shipping_method' => 'golocal',
            'payment_method' => 'gcash',
            'items' => $items,
        ])->assertStatus(201)->json('data.order_number');
    }

    /**
     * Move an order to the last stage. There is no admin endpoint for this yet, so the
     * fixture writes what fulfilment would have written — including eta, which is the
     * delivery signal the window is measured from.
     */
    private function deliver(string $orderNumber, int $daysAgo = 1): Order
    {
        $order = Order::where('order_number', $orderNumber)->firstOrFail();

        // Backdate every date the window could be anchored on. reefer.returns.window_from
        // chooses between purchase and delivery, so ageing only one of them would make
        // this test quietly pass under one setting and fail under the other.
        $order->forceFill([
            'status' => 'Delivered',
            'stage' => 4,
            'eta' => now()->subDays($daysAgo),
            'delivered_at' => now()->subDays($daysAgo),
            'placed_at' => now()->subDays($daysAgo),
        ])->save();

        return $order;
    }

    private function payload(array $items = [['slug' => 'test-tee', 'size' => 'M', 'qty' => 1]], string $reason = 'wrong_size'): array
    {
        return ['reason' => $reason, 'note' => 'Too tight across the chest.', 'items' => $items];
    }

    // --- the happy path --------------------------------------------------------

    public function test_a_delivered_in_window_order_can_be_returned(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.reference', 'RET-000001')
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.reason', 'wrong_size')
            ->assertJsonPath('data.reason_label', 'Wrong size')
            ->assertJsonPath('data.order_number', $number)
            ->assertJsonPath('data.refund_subtotal', 1200)
            ->assertJsonPath('data.refund_subtotal_formatted', '₱1,200')
            ->assertJsonPath('data.resolved_at', null)
            ->assertJsonPath('data.items.0.slug', 'test-tee')
            ->assertJsonPath('data.items.0.size', 'M')
            ->assertJsonPath('data.items.0.qty', 1)
            ->assertJsonPath('data.items.0.unit_price', 1200)
            ->assertJsonPath('data.items.0.line_total', 1200);

        $this->assertDatabaseHas('storefront_return_requests', [
            'reference' => 'RET-000001',
            'status' => 'requested',
            'reason' => 'wrong_size',
        ]);
        $this->assertSame(1, ReturnRequestItem::count());
    }

    public function test_the_refund_is_priced_off_the_order_line_not_todays_catalog(): void
    {
        $this->makeProduct(price: 1200);
        $token = $this->token();
        $number = $this->buy($token, [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]]);
        $this->deliver($number);

        // The shop marks it up after the sale. The return is still worth what was paid.
        Product::where('slug', 'test-tee')->update(['price' => 9999]);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]]))
            ->assertStatus(201)
            ->assertJsonPath('data.refund_subtotal', 2400)
            ->assertJsonPath('data.items.0.unit_price', 1200);
    }

    public function test_an_order_with_no_eta_falls_back_to_the_placed_date(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);

        // Delivered, but fulfilment never set an eta — placed_at is the fallback, and
        // the order was placed moments ago, so the window is open.
        Order::where('order_number', $number)->update(['status' => 'Delivered', 'stage' => 4, 'eta' => null]);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())
            ->assertStatus(201);
    }

    public function test_the_acknowledgement_is_mailed_to_the_order_email(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number);

        Mail::fake();

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())
            ->assertStatus(201);

        Mail::assertSent(
            ReturnRequestedMail::class,
            fn (ReturnRequestedMail $mail) => $mail->hasTo('buyer@example.com')
                && $mail->returnRequest->reference === 'RET-000001',
        );
    }

    public function test_the_acknowledgement_template_renders(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number);

        $this->withToken($token)->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())->assertStatus(201);

        // sendAcknowledgement swallows a throwing mailer on purpose, so a broken
        // template would pass every test above it. Render it here where it cannot hide.
        $html = (new ReturnRequestedMail(ReturnRequest::firstOrFail()))->render();

        $this->assertStringContainsString('RET-000001', $html);
        $this->assertStringContainsString('Wrong size', $html);
        $this->assertStringContainsString('1,200', $html);
    }

    // --- the order-level gates -------------------------------------------------

    public function test_an_order_that_is_not_delivered_cannot_be_returned(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');

        $this->assertSame(0, ReturnRequest::count());
    }

    public function test_out_for_delivery_is_still_not_delivered(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        Order::where('order_number', $number)->update(['stage' => 3]);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    }

    public function test_a_delivery_older_than_the_window_cannot_be_returned(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number, daysAgo: (int) config('reefer.returns.window_days') + 1);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');

        $this->assertSame(0, ReturnRequest::count());
    }

    public function test_the_last_day_of_the_window_still_works(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number, daysAgo: (int) config('reefer.returns.window_days'));

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())
            ->assertStatus(201);
    }

    // --- ownership -------------------------------------------------------------

    public function test_someone_elses_order_is_a_404_not_a_403(): void
    {
        $this->makeProduct();
        $buyer = $this->token('buyer@example.com');
        $stranger = $this->token('stranger@example.com', 'Ana Cruz');
        $number = $this->buy($buyer);
        $this->deliver($number);

        $this->withToken($stranger)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())
            ->assertStatus(404);

        $this->assertSame(0, ReturnRequest::count());
    }

    public function test_an_order_that_does_not_exist_is_a_404(): void
    {
        $this->makeProduct();

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/orders/RFR-PH9999999/returns', $this->payload())
            ->assertStatus(404);
    }

    public function test_returns_need_an_account(): void
    {
        $this->makeProduct();
        $number = $this->buy($this->token());
        $this->deliver($number);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())->assertStatus(401);
        $this->getJson('/api/storefront/v1/returns')->assertStatus(401);
    }

    // --- quantity --------------------------------------------------------------

    public function test_you_cannot_return_more_than_you_bought(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token, [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]]);
        $this->deliver($number);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([['slug' => 'test-tee', 'size' => 'M', 'qty' => 3]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, ReturnRequest::count());
    }

    public function test_splitting_one_line_across_two_payload_entries_does_not_beat_the_limit(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token, [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]]);
        $this->deliver($number);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([
                ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2],
                ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, ReturnRequest::count());
    }

    public function test_an_item_from_another_order_is_refused(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token, [['slug' => 'test-tee', 'size' => 'M', 'qty' => 1]]);
        $this->deliver($number);

        // Size L exists in the catalog but is not on this order.
        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([['slug' => 'test-tee', 'size' => 'L', 'qty' => 1]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_a_second_return_for_an_already_returned_line_is_refused(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token, [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]]);
        $this->deliver($number);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]]))
            ->assertStatus(201);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([['slug' => 'test-tee', 'size' => 'M', 'qty' => 1]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(1, ReturnRequest::count());
    }

    public function test_a_partial_return_leaves_the_rest_returnable(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token, [['slug' => 'test-tee', 'size' => 'M', 'qty' => 3]]);
        $this->deliver($number);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([['slug' => 'test-tee', 'size' => 'M', 'qty' => 1]]))
            ->assertStatus(201);

        // Two left: asking for three fails, asking for two works.
        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([['slug' => 'test-tee', 'size' => 'M', 'qty' => 3]]))
            ->assertStatus(422);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]]))
            ->assertStatus(201)
            // The refused attempt in between never got as far as an insert, so it
            // burned no reference.
            ->assertJsonPath('data.reference', 'RET-000002');
    }

    public function test_cancelling_hands_the_quantity_back(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number);

        $this->withToken($token)->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())->assertStatus(201);
        $this->withToken($token)->postJson('/api/storefront/v1/returns/RET-000001/cancel')->assertStatus(200);

        // The unit is free again, so the same line can be sent back on a fresh return.
        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.reference', 'RET-000002');
    }

    // --- validation ------------------------------------------------------------

    public function test_the_reason_must_be_one_of_the_listed_ones(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload(reason: 'i-just-hate-it'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(0, ReturnRequest::count());
    }

    public function test_a_return_needs_at_least_one_item(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number);

        $this->withToken($token)
            ->postJson("/api/storefront/v1/orders/{$number}/returns", ['reason' => 'damaged', 'items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_zero_and_negative_quantities_are_refused(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token, [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]]);
        $this->deliver($number);

        foreach ([0, -1] as $bad) {
            $this->withToken($token)
                ->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload([['slug' => 'test-tee', 'size' => 'M', 'qty' => $bad]]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('items.0.qty');
        }

        $this->assertSame(0, ReturnRequest::count());
    }

    // --- cancel ----------------------------------------------------------------

    public function test_the_owner_can_cancel_while_it_is_still_requested(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number);
        $this->withToken($token)->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload());

        $this->withToken($token)->postJson('/api/storefront/v1/returns/RET-000001/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull(ReturnRequest::firstOrFail()->resolved_at);
    }

    public function test_cancelling_twice_fails(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number);
        $this->withToken($token)->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload());

        $this->withToken($token)->postJson('/api/storefront/v1/returns/RET-000001/cancel')->assertStatus(200);
        $this->withToken($token)->postJson('/api/storefront/v1/returns/RET-000001/cancel')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_return_the_shop_has_acted_on_cannot_be_cancelled(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $number = $this->buy($token);
        $this->deliver($number);
        $this->withToken($token)->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload());

        ReturnRequest::firstOrFail()->forceFill(['status' => 'approved'])->save();

        $this->withToken($token)->postJson('/api/storefront/v1/returns/RET-000001/cancel')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('approved', ReturnRequest::firstOrFail()->status);
    }

    public function test_you_cannot_cancel_someone_elses_return(): void
    {
        $this->makeProduct();
        $buyer = $this->token('buyer@example.com');
        $stranger = $this->token('stranger@example.com', 'Ana Cruz');
        $number = $this->buy($buyer);
        $this->deliver($number);
        $this->withToken($buyer)->postJson("/api/storefront/v1/orders/{$number}/returns", $this->payload());

        $this->withToken($stranger)->postJson('/api/storefront/v1/returns/RET-000001/cancel')->assertStatus(404);

        $this->assertSame('requested', ReturnRequest::firstOrFail()->status);
    }

    // --- reading ---------------------------------------------------------------

    public function test_index_and_show_are_scoped_to_the_caller(): void
    {
        $this->makeProduct();
        $buyer = $this->token('buyer@example.com');
        $stranger = $this->token('stranger@example.com', 'Ana Cruz');

        $mine = $this->buy($buyer);
        $this->deliver($mine);
        $this->withToken($buyer)->postJson("/api/storefront/v1/orders/{$mine}/returns", $this->payload())->assertStatus(201);

        $theirs = $this->buy($stranger, email: 'stranger@example.com');
        $this->deliver($theirs);
        $this->withToken($stranger)->postJson("/api/storefront/v1/orders/{$theirs}/returns", $this->payload())->assertStatus(201);

        $this->withToken($buyer)->getJson('/api/storefront/v1/returns')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'RET-000001')
            ->assertJsonPath('data.0.order_number', $mine);

        $this->withToken($buyer)->getJson('/api/storefront/v1/returns/RET-000001')
            ->assertStatus(200)
            ->assertJsonPath('data.reference', 'RET-000001');

        // The other account's return exists, but not for this caller.
        $this->withToken($buyer)->getJson('/api/storefront/v1/returns/RET-000002')->assertStatus(404);
        $this->withToken($stranger)->getJson('/api/storefront/v1/returns')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'RET-000002');
    }

    public function test_a_reference_that_does_not_exist_is_a_404(): void
    {
        $this->withToken($this->token())->getJson('/api/storefront/v1/returns/RET-999999')->assertStatus(404);
    }
}
