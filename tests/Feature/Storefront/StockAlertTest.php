<?php

namespace Tests\Feature\Storefront;

use App\Mail\Storefront\BackInStockMail;
use App\Models\Storefront\Customer;
use App\Models\Storefront\Product;
use App\Models\Storefront\ProductVariant;
use App\Models\Storefront\StockAlert;
use App\Services\Storefront\StockAlertNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StockAlertTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $slug = 'sold-out-tee', int $stock = 0, bool $active = true): Product
    {
        $product = Product::create([
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'audience' => 'unisex',
            'type' => 'tee',
            'price' => 1450,
            'is_active' => $active,
            'sort' => 1,
        ]);

        $product->variants()->create(['size' => 'M', 'sku' => strtoupper($slug).'-M', 'on_hand' => $stock]);
        $product->variants()->create(['size' => 'L', 'sku' => strtoupper($slug).'-L', 'on_hand' => 7]);

        return $product;
    }

    private function token(string $email = 'fan@example.com'): string
    {
        return $this->postJson('/api/storefront/v1/auth/register', [
            'name' => 'Fan',
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    private function variant(string $slug = 'sold-out-tee', string $size = 'M'): ProductVariant
    {
        return ProductVariant::whereRelation('product', 'slug', $slug)->where('size', $size)->firstOrFail();
    }

    /** An alert straight into the table, for the notifier tests. */
    private function alertFor(string $email, ProductVariant $variant): StockAlert
    {
        $alert = new StockAlert();
        $alert->user_id = Customer::where('email', $email)->value('id');
        $alert->product_variant_id = $variant->id;
        $alert->save();

        return $alert;
    }

    private function notifier(): StockAlertNotifier
    {
        return app(StockAlertNotifier::class);
    }

    // --- signed in is required --------------------------------------------------

    public function test_every_stock_alert_route_requires_authentication(): void
    {
        $this->makeProduct();
        $this->token();
        // A real id, so the 401 is the auth middleware talking and not route-model
        // binding failing to find the row.
        $alert = $this->alertFor('fan@example.com', $this->variant());

        $this->getJson('/api/storefront/v1/stock-alerts')->assertStatus(401);
        $this->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M'])->assertStatus(401);
        $this->deleteJson("/api/storefront/v1/stock-alerts/{$alert->id}")->assertStatus(401);
    }

    // --- creating ---------------------------------------------------------------

    public function test_asking_to_be_told_creates_a_pending_alert(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'sold-out-tee')
            ->assertJsonPath('data.size', 'M')
            ->assertJsonPath('data.product_name', 'Sold Out Tee')
            ->assertJsonPath('data.price_formatted', '₱1,450')
            ->assertJsonPath('data.in_stock', false)
            ->assertJsonPath('data.notified_at', null);

        $this->assertDatabaseHas('storefront_stock_alerts', [
            'user_id' => Customer::firstWhere('email', 'fan@example.com')->id,
            'product_variant_id' => $this->variant()->id,
            'notified_at' => null,
        ]);
    }

    public function test_the_list_comes_back_newest_first(): void
    {
        $this->makeProduct('tee-a');
        $this->makeProduct('tee-b');
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'tee-a', 'size' => 'M']);
        $this->withToken($token)->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'tee-b', 'size' => 'M']);

        $this->withToken($token)->getJson('/api/storefront/v1/stock-alerts')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'tee-b')
            ->assertJsonPath('data.1.slug', 'tee-a');
    }

    public function test_asking_twice_is_a_conflict_not_a_second_row(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M'])
            ->assertStatus(201);

        $this->withToken($token)->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M'])
            ->assertStatus(409);

        $this->assertDatabaseCount('storefront_stock_alerts', 1);
    }

    public function test_a_size_that_is_in_stock_is_refused(): void
    {
        // Size L was seeded with stock, so there is nothing to wait for.
        $this->makeProduct();

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'L'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');

        $this->assertDatabaseCount('storefront_stock_alerts', 0);
    }

    public function test_an_unknown_size_is_refused(): void
    {
        $this->makeProduct();

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => '4XL'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size');
    }

    public function test_a_deactivated_product_cannot_be_alerted_on(): void
    {
        $this->makeProduct(active: false);

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_an_unknown_product_is_refused(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'ghost', 'size' => 'M'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_a_deactivated_product_drops_out_of_the_list(): void
    {
        $product = $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M']);

        $product->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/storefront/v1/stock-alerts')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    // --- isolation --------------------------------------------------------------

    public function test_one_account_cannot_see_or_delete_anothers_alert(): void
    {
        $this->makeProduct();
        $alice = $this->token('alice@example.com');
        $bob = $this->token('bob@example.com');

        $id = $this->withToken($alice)
            ->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M'])
            ->json('data.id');

        // 404, not 403 — Bob must not be able to confirm the id exists.
        $this->withToken($bob)->deleteJson("/api/storefront/v1/stock-alerts/{$id}")->assertStatus(404);
        $this->withToken($bob)->getJson('/api/storefront/v1/stock-alerts')->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('storefront_stock_alerts', ['id' => $id]);
    }

    public function test_deleting_your_own_alert(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $id = $this->withToken($token)
            ->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M'])
            ->json('data.id');

        $this->withToken($token)->deleteJson("/api/storefront/v1/stock-alerts/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Alert removed.');

        $this->assertDatabaseCount('storefront_stock_alerts', 0);
    }

    public function test_deleting_the_user_clears_their_alerts(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $this->withToken($token)->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M']);

        Customer::firstWhere('email', 'fan@example.com')->delete();

        $this->assertDatabaseCount('storefront_stock_alerts', 0);
    }

    // --- the notifier -----------------------------------------------------------

    public function test_a_restock_mails_once_and_stamps_notified_at(): void
    {
        $this->makeProduct();
        $this->token();
        $alert = $this->alertFor('fan@example.com', $this->variant());

        $this->variant()->update(['on_hand' => 4]);

        Mail::fake();
        $this->assertSame(1, $this->notifier()->run());

        Mail::assertSent(BackInStockMail::class, 1);
        Mail::assertSent(BackInStockMail::class, fn ($mail) => $mail->hasTo('fan@example.com')
            && $mail->variant->size === 'M');

        $this->assertNotNull($alert->fresh()->notified_at);
    }

    public function test_a_second_run_does_not_mail_again(): void
    {
        $this->makeProduct();
        $this->token();
        $this->alertFor('fan@example.com', $this->variant());

        $this->variant()->update(['on_hand' => 4]);

        Mail::fake();
        $this->notifier()->run();
        $this->assertSame(0, $this->notifier()->run(), 'a claimed alert must not be picked up twice');

        Mail::assertSent(BackInStockMail::class, 1);
    }

    public function test_a_variant_still_out_of_stock_is_not_mailed(): void
    {
        $this->makeProduct();
        $this->token();
        $alert = $this->alertFor('fan@example.com', $this->variant());

        Mail::fake();
        $this->assertSame(0, $this->notifier()->run());

        Mail::assertNothingSent();
        $this->assertNull($alert->fresh()->notified_at);
    }

    public function test_a_restock_on_a_pulled_product_is_not_mailed(): void
    {
        $product = $this->makeProduct();
        $this->token();
        $alert = $this->alertFor('fan@example.com', $this->variant());

        $this->variant()->update(['on_hand' => 4]);
        $product->update(['is_active' => false]);

        Mail::fake();
        $this->notifier()->run();

        Mail::assertNothingSent();
        $this->assertNull($alert->fresh()->notified_at);
    }

    public function test_a_run_is_capped_by_config(): void
    {
        config(['reefer.stock_alerts.max_per_run' => 1]);

        $this->makeProduct('tee-a');
        $this->makeProduct('tee-b');
        $this->token();
        $this->alertFor('fan@example.com', $this->variant('tee-a'));
        $this->alertFor('fan@example.com', $this->variant('tee-b'));

        $this->variant('tee-a')->update(['on_hand' => 3]);
        $this->variant('tee-b')->update(['on_hand' => 3]);

        Mail::fake();
        $this->assertSame(1, $this->notifier()->run());

        Mail::assertSent(BackInStockMail::class, 1);
    }

    public function test_the_artisan_command_runs_the_notifier(): void
    {
        $this->makeProduct();
        $this->token();
        $this->alertFor('fan@example.com', $this->variant());
        $this->variant()->update(['on_hand' => 2]);

        Mail::fake();
        $this->artisan('reefer:notify-back-in-stock')->assertSuccessful();

        Mail::assertSent(BackInStockMail::class, 1);
    }

    public function test_a_notified_alert_can_be_re_armed_once_the_size_sells_out_again(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $alert = $this->alertFor('fan@example.com', $this->variant());

        $this->variant()->update(['on_hand' => 2]);
        Mail::fake();
        $this->notifier()->run();
        $this->assertNotNull($alert->fresh()->notified_at);

        // Sold out again — the same account may ask again, and the unique index means
        // it has to be the same row.
        $this->variant()->update(['on_hand' => 0]);

        $this->withToken($token)->postJson('/api/storefront/v1/stock-alerts', ['slug' => 'sold-out-tee', 'size' => 'M'])
            ->assertStatus(201)
            ->assertJsonPath('data.notified_at', null);

        $this->assertDatabaseCount('storefront_stock_alerts', 1);
        $this->assertNull($alert->fresh()->notified_at);
    }

    // --- the email itself -------------------------------------------------------

    public function test_the_email_names_the_product_and_links_to_its_page(): void
    {
        $this->makeProduct();
        $this->token();
        $user = Customer::firstWhere('email', 'fan@example.com');

        $html = (new BackInStockMail($user, $this->variant()->load('product')))->render();

        $this->assertStringContainsString('Sold Out Tee', $html);
        $this->assertStringContainsString('size M', $html);
        $this->assertStringContainsString('₱1,450', $html);
        $this->assertStringContainsString('/product/sold-out-tee', $html);
    }
}
