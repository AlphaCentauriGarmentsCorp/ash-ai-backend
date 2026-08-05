<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Cart;
use App\Models\Storefront\CartItem;
use App\Models\Storefront\Product;
use App\Models\Storefront\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $slug = 'test-tee', int $price = 1200, int $stock = 10, bool $active = true): Product
    {
        $product = Product::create([
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'audience' => 'unisex',
            'type' => 'tee',
            'price' => $price,
            'is_active' => $active,
            'sort' => 1,
        ]);

        foreach (['S', 'M', 'L'] as $size) {
            $product->variants()->create([
                'size' => $size,
                'sku' => strtoupper($slug).'-'.$size,
                'on_hand' => $stock,
            ]);
        }

        return $product;
    }

    private function token(string $email = 'shopper@example.com'): string
    {
        return $this->postJson('/api/storefront/v1/auth/register', [
            'name' => 'Shopper',
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    // --- the whole point: it survives ------------------------------------------

    public function test_a_cart_persists_across_requests_and_devices(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2])
            ->assertStatus(201)
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.items.0.key', 'test-tee|M');

        $this->assertDatabaseHas('storefront_cart_items', ['qty' => 2]);

        // A completely separate request — i.e. another device using the same
        // account — sees the same cart. That is the whole reason this exists.
        $this->withToken($token)->getJson('/api/storefront/v1/cart')
            ->assertStatus(200)
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.subtotal', 2400)
            ->assertJsonPath('data.items.0.slug', 'test-tee');
    }

    public function test_each_account_gets_exactly_one_cart(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M']);
        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'L']);
        $this->withToken($token)->getJson('/api/storefront/v1/cart');

        $this->assertSame(1, Cart::count(), 'repeated use must not spawn extra carts');
    }

    public function test_adding_the_same_size_bumps_quantity_instead_of_duplicating(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]);
        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 3])
            ->assertStatus(201)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.qty', 5);

        $this->assertSame(1, CartItem::count());
    }

    public function test_different_sizes_are_separate_lines(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M']);
        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'L'])
            ->assertJsonCount(2, 'data.items');
    }

    // --- pricing is live, never snapshotted -------------------------------------

    public function test_cart_reprices_when_the_catalog_price_changes(): void
    {
        $product = $this->makeProduct(price: 1200);
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2])
            ->assertJsonPath('data.subtotal', 2400);

        $product->update(['price' => 1500]);

        // A cart must show today's price. If this ever fails, someone has started
        // snapshotting the price into cart_items — that is what order_items is for.
        $this->withToken($token)->getJson('/api/storefront/v1/cart')
            ->assertJsonPath('data.items.0.unit_price', 1500)
            ->assertJsonPath('data.subtotal', 3000);
    }

    public function test_a_line_whose_variant_disappears_is_dropped_from_the_response(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M']);
        ProductVariant::where('size', 'M')->delete();

        $this->withToken($token)->getJson('/api/storefront/v1/cart')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.items');
    }

    // --- quantity + removal -----------------------------------------------------

    public function test_quantity_can_be_set_and_the_line_removed(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $id = $this->withToken($token)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2])
            ->json('data.items.0.id');

        $this->withToken($token)->patchJson("/api/storefront/v1/cart/items/$id", ['qty' => 5])
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.qty', 5);

        $this->withToken($token)->deleteJson("/api/storefront/v1/cart/items/$id")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.items');

        $this->assertDatabaseMissing('storefront_cart_items', ['id' => $id]);
    }

    public function test_setting_quantity_to_zero_removes_the_line(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $id = $this->withToken($token)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M'])
            ->json('data.items.0.id');

        $this->withToken($token)->patchJson("/api/storefront/v1/cart/items/$id", ['qty' => 0])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_cart_can_be_emptied(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M']);
        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'L']);

        $this->withToken($token)->deleteJson('/api/storefront/v1/cart')
            ->assertStatus(200)
            ->assertJsonPath('data.count', 0);

        $this->assertSame(0, CartItem::count());
        $this->assertSame(1, Cart::count(), 'clearing empties the cart, it does not delete it');
    }

    // --- stock ------------------------------------------------------------------

    public function test_cannot_add_more_than_stock(): void
    {
        $this->makeProduct(stock: 3);
        $token = $this->token();

        $this->withToken($token)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qty');

        $this->assertSame(0, CartItem::count());
    }

    public function test_repeated_adds_are_checked_against_the_running_total(): void
    {
        $this->makeProduct(stock: 3);
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2])
            ->assertStatus(201);

        // 2 already in the cart + 2 more = 4, over the 3 in stock. The check must
        // see the total, not just this request's 2.
        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qty');

        $this->withToken($token)->getJson('/api/storefront/v1/cart')->assertJsonPath('data.items.0.qty', 2);
    }

    public function test_a_cart_line_that_went_out_of_stock_is_flagged_not_hidden(): void
    {
        $this->makeProduct(stock: 10);
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 5]);

        // Stock drops while the cart sits there — nothing is reserved until checkout.
        ProductVariant::where('size', 'M')->update(['on_hand' => 2]);

        $this->withToken($token)->getJson('/api/storefront/v1/cart')
            ->assertJsonPath('data.items.0.exceeds_stock', true)
            ->assertJsonPath('data.items.0.stock', 2)
            ->assertJsonPath('data.items.0.qty', 5);
    }

    public function test_deactivated_product_cannot_be_added(): void
    {
        $this->makeProduct(active: false);

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_unavailable_size_is_rejected(): void
    {
        $this->makeProduct();

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'XXL'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    // --- authorization ----------------------------------------------------------

    public function test_a_user_cannot_touch_another_users_cart_item(): void
    {
        $this->makeProduct();
        $alice = $this->token('alice@example.com');
        $bob = $this->token('bob@example.com');

        $bobsItem = $this->withToken($bob)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2])
            ->json('data.items.0.id');

        $this->withToken($alice)->patchJson("/api/storefront/v1/cart/items/$bobsItem", ['qty' => 99])->assertNotFound();
        $this->withToken($alice)->deleteJson("/api/storefront/v1/cart/items/$bobsItem")->assertNotFound();

        $this->assertDatabaseHas('storefront_cart_items', ['id' => $bobsItem, 'qty' => 2]);
        $this->withToken($alice)->getJson('/api/storefront/v1/cart')->assertJsonCount(0, 'data.items');
    }

    public function test_carts_are_isolated_between_accounts(): void
    {
        $this->makeProduct();
        $alice = $this->token('alice@example.com');
        $bob = $this->token('bob@example.com');

        $this->withToken($alice)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 3]);

        $this->withToken($bob)->getJson('/api/storefront/v1/cart')->assertJsonPath('data.count', 0);
        $this->withToken($alice)->getJson('/api/storefront/v1/cart')->assertJsonPath('data.count', 3);
    }

    public function test_every_cart_route_requires_authentication(): void
    {
        $this->getJson('/api/storefront/v1/cart')->assertStatus(401);
        $this->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M'])->assertStatus(401);
        $this->deleteJson('/api/storefront/v1/cart')->assertStatus(401);
        $this->postJson('/api/storefront/v1/cart/merge', ['items' => []])->assertStatus(401);
        $this->patchJson('/api/storefront/v1/cart/items/1', ['qty' => 1])->assertStatus(401);
        $this->deleteJson('/api/storefront/v1/cart/items/1')->assertStatus(401);
    }

    // --- merge ------------------------------------------------------------------

    public function test_merge_lifts_a_local_cart_into_the_account(): void
    {
        $this->makeProduct();
        $this->makeProduct(slug: 'other-tee', price: 800);
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/merge', ['items' => [
            ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2],
            ['slug' => 'other-tee', 'size' => 'L', 'qty' => 1],
        ]])->assertStatus(200)->assertJsonPath('data.count', 3);

        $this->withToken($token)->getJson('/api/storefront/v1/cart')->assertJsonPath('data.subtotal', 2400 + 800);
    }

    public function test_merge_combines_with_what_is_already_in_the_account_cart(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]);

        $this->withToken($token)->postJson('/api/storefront/v1/cart/merge', ['items' => [
            ['slug' => 'test-tee', 'size' => 'M', 'qty' => 3],
        ]])->assertStatus(200)->assertJsonPath('data.items.0.qty', 5);
    }

    public function test_merge_clamps_to_stock_rather_than_failing(): void
    {
        $this->makeProduct(stock: 3);
        $token = $this->token();

        // A browser cart that went stale should not block sign-in.
        $this->withToken($token)->postJson('/api/storefront/v1/cart/merge', ['items' => [
            ['slug' => 'test-tee', 'size' => 'M', 'qty' => 99],
        ]])->assertStatus(200)->assertJsonPath('data.items.0.qty', 3);
    }

    public function test_merging_an_empty_list_is_a_no_op(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]);
        $this->withToken($token)->postJson('/api/storefront/v1/cart/merge', ['items' => []])
            ->assertStatus(200)
            ->assertJsonPath('data.count', 2);
    }

    // --- choosing what to check out ---------------------------------------------

    public function test_new_items_start_selected(): void
    {
        $this->makeProduct();

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2])
            ->assertJsonPath('data.items.0.selected', true)
            ->assertJsonPath('data.selected_count', 2)
            ->assertJsonPath('data.all_selected', true);
    }

    public function test_unticking_a_line_persists_and_keeps_it_in_the_cart(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $id = $this->withToken($token)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2])
            ->json('data.items.0.id');

        $this->withToken($token)->patchJson("/api/storefront/v1/cart/items/$id", ['selected' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.selected', false);

        $this->assertDatabaseHas('storefront_cart_items', ['id' => $id, 'selected' => false]);

        // The whole point of unticking rather than removing: it is still there, and
        // a different device sees the same choice.
        $this->withToken($token)->getJson('/api/storefront/v1/cart')
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.items.0.selected', false)
            ->assertJsonPath('data.selected_count', 0);
    }

    public function test_totals_split_between_the_whole_cart_and_the_selection(): void
    {
        $this->makeProduct(price: 1200);
        $this->makeProduct(slug: 'other-tee', price: 800);
        $token = $this->token();

        $keep = $this->withToken($token)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 1])
            ->json('data.items.0.id');
        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'other-tee', 'size' => 'M', 'qty' => 1]);

        $this->withToken($token)->patchJson("/api/storefront/v1/cart/items/$keep", ['selected' => false]);

        $this->withToken($token)->getJson('/api/storefront/v1/cart')
            // The nav badge still counts everything…
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.subtotal', 2000)
            // …while the order summary only counts what is ticked.
            ->assertJsonPath('data.selected_count', 1)
            ->assertJsonPath('data.selected_subtotal', 800)
            ->assertJsonPath('data.all_selected', false);
    }

    public function test_select_all_ticks_and_unticks_everything(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M']);
        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'L']);

        $this->withToken($token)->postJson('/api/storefront/v1/cart/select', ['selected' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.selected_count', 0)
            ->assertJsonPath('data.all_selected', false);

        $this->withToken($token)->postJson('/api/storefront/v1/cart/select', ['selected' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.selected_count', 2)
            ->assertJsonPath('data.all_selected', true);
    }

    public function test_all_selected_is_false_on_an_empty_cart(): void
    {
        $this->withToken($this->token())->getJson('/api/storefront/v1/cart')
            ->assertJsonPath('data.all_selected', false);
    }

    public function test_quantity_and_selection_can_change_in_one_request(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $id = $this->withToken($token)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M'])
            ->json('data.items.0.id');

        $this->withToken($token)->patchJson("/api/storefront/v1/cart/items/$id", ['qty' => 4, 'selected' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.qty', 4)
            ->assertJsonPath('data.items.0.selected', false);
    }

    public function test_an_empty_patch_is_rejected(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $id = $this->withToken($token)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M'])
            ->json('data.items.0.id');

        $this->withToken($token)->patchJson("/api/storefront/v1/cart/items/$id", [])->assertStatus(422);
    }

    public function test_selection_is_scoped_to_the_owner(): void
    {
        $this->makeProduct();
        $alice = $this->token('alice@example.com');
        $bob = $this->token('bob@example.com');

        $bobsItem = $this->withToken($bob)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M'])
            ->json('data.items.0.id');

        $this->withToken($alice)->patchJson("/api/storefront/v1/cart/items/$bobsItem", ['selected' => false])->assertNotFound();
        $this->assertDatabaseHas('storefront_cart_items', ['id' => $bobsItem, 'selected' => true]);

        // Alice's select-all must not reach into Bob's cart.
        $this->withToken($alice)->postJson('/api/storefront/v1/cart/select', ['selected' => false])->assertStatus(200);
        $this->assertDatabaseHas('storefront_cart_items', ['id' => $bobsItem, 'selected' => true]);
    }

    // --- checkout hand-off ------------------------------------------------------

    public function test_placing_an_order_removes_only_what_was_ordered(): void
    {
        $this->makeProduct(stock: 10);
        $this->makeProduct(slug: 'other-tee', stock: 10);
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]);
        $keepId = $this->withToken($token)
            ->postJson('/api/storefront/v1/cart/items', ['slug' => 'other-tee', 'size' => 'L', 'qty' => 1])
            ->json('data.items.1.id');

        // Leave the second one behind for next payday.
        $this->withToken($token)->patchJson("/api/storefront/v1/cart/items/$keepId", ['selected' => false]);

        $this->withToken($token)->postJson('/api/storefront/v1/orders', [
            'email' => 'shopper@example.com',
            'ship_to_name' => 'Shopper',
            'phone' => '09170000001',
            'street' => '1 Main St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'shipping_method' => 'golocal',
            'payment_method' => 'gcash',
            'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]],
        ])->assertStatus(201);

        // Buying two things must not bin the four you left unticked.
        $this->withToken($token)->getJson('/api/storefront/v1/cart')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.slug', 'other-tee')
            ->assertJsonPath('data.items.0.selected', false);

        $this->assertDatabaseHas('storefront_cart_items', ['id' => $keepId]);
    }

    public function test_ordering_everything_still_empties_the_cart(): void
    {
        $this->makeProduct(stock: 10);
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]);

        $this->withToken($token)->postJson('/api/storefront/v1/orders', [
            'email' => 'shopper@example.com',
            'ship_to_name' => 'Shopper',
            'phone' => '09170000001',
            'street' => '1 Main St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'shipping_method' => 'golocal',
            'payment_method' => 'gcash',
            'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]],
        ])->assertStatus(201);

        $this->withToken($token)->getJson('/api/storefront/v1/cart')->assertJsonPath('data.count', 0);
    }

    public function test_a_declined_order_leaves_the_cart_intact(): void
    {
        $this->makeProduct(stock: 10);
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/cart/items', ['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]);

        $this->withToken($token)->postJson('/api/storefront/v1/orders', [
            'email' => 'shopper@example.com',
            'ship_to_name' => 'Shopper',
            'phone' => '09170000001',
            'street' => '1 Main St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'shipping_method' => 'golocal',
            'payment_method' => 'gcash',
            'simulate' => 'fail',
            'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]],
        ])->assertStatus(402);

        // Losing the cart on a failed payment would be a cruel way to lose a sale.
        $this->withToken($token)->getJson('/api/storefront/v1/cart')->assertJsonPath('data.count', 2);
    }
}
