<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $slug, int $price = 1200, bool $active = true): Product
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

        $product->variants()->create(['size' => 'M', 'sku' => strtoupper($slug).'-M', 'on_hand' => 10]);

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

    // --- signed in is required --------------------------------------------------

    public function test_every_favorites_route_requires_authentication(): void
    {
        $this->makeProduct('tee-a');

        $this->getJson('/api/storefront/v1/favorites')->assertStatus(401);
        $this->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a'])->assertStatus(401);
        $this->deleteJson('/api/storefront/v1/favorites/tee-a')->assertStatus(401);
    }

    // --- toggle -----------------------------------------------------------------

    public function test_toggling_adds_then_removes(): void
    {
        $this->makeProduct('tee-a');
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a'])
            ->assertStatus(200)
            ->assertJsonPath('favorited', true)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.slug', 'tee-a');

        $this->assertDatabaseHas('storefront_favorites', ['product_id' => Product::firstWhere('slug', 'tee-a')->id]);

        // Toggling again removes it.
        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a'])
            ->assertStatus(200)
            ->assertJsonPath('favorited', false)
            ->assertJsonPath('count', 0);

        $this->assertDatabaseCount('storefront_favorites', 0);
    }

    public function test_a_wishlist_survives_across_requests(): void
    {
        $this->makeProduct('tee-a');
        $this->makeProduct('tee-b');
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a']);
        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-b']);

        // A separate request — i.e. another device — sees the same wishlist.
        $this->withToken($token)->getJson('/api/storefront/v1/favorites')
            ->assertStatus(200)
            ->assertJsonPath('count', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('slugs', ['tee-b', 'tee-a']); // newest first
    }

    public function test_favoriting_the_same_product_twice_does_not_duplicate(): void
    {
        $this->makeProduct('tee-a');
        $token = $this->token();

        // Two "adds" — the join table's unique index keeps it a set, but toggle
        // would flip it, so use the fact that a second identical add is a no-op via
        // the DB constraint. Toggle on/off/on ends with exactly one row.
        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a']);
        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a']);
        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a']);

        $this->assertDatabaseCount('storefront_favorites', 1);
    }

    public function test_removing_a_favorite(): void
    {
        $this->makeProduct('tee-a');
        $this->makeProduct('tee-b');
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a']);
        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-b']);

        $this->withToken($token)->deleteJson('/api/storefront/v1/favorites/tee-a')
            ->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.slug', 'tee-b');

        $this->assertDatabaseMissing('storefront_favorites', ['product_id' => Product::firstWhere('slug', 'tee-a')->id]);
    }

    // --- isolation --------------------------------------------------------------

    public function test_wishlists_are_private_to_each_account(): void
    {
        $this->makeProduct('tee-a');
        $alice = $this->token('alice@example.com');
        $bob = $this->token('bob@example.com');

        $this->withToken($alice)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a']);

        $this->withToken($bob)->getJson('/api/storefront/v1/favorites')->assertJsonPath('count', 0);
        $this->withToken($alice)->getJson('/api/storefront/v1/favorites')->assertJsonPath('count', 1);
    }

    public function test_one_account_cannot_delete_anothers_favorite(): void
    {
        $this->makeProduct('tee-a');
        $alice = $this->token('alice@example.com');
        $bob = $this->token('bob@example.com');

        $this->withToken($alice)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a']);

        // Bob deleting "tee-a" only ever touches Bob's (empty) wishlist.
        $this->withToken($bob)->deleteJson('/api/storefront/v1/favorites/tee-a')->assertStatus(200);

        $this->assertDatabaseHas('storefront_favorites', [
            'user_id' => \App\Models\Storefront\Customer::firstWhere('email', 'alice@example.com')->id,
            'product_id' => Product::firstWhere('slug', 'tee-a')->id,
        ]);
    }

    // --- catalog hygiene --------------------------------------------------------

    public function test_a_deactivated_product_drops_out_of_the_wishlist(): void
    {
        $product = $this->makeProduct('tee-a');
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a'])
            ->assertJsonPath('count', 1);

        $product->update(['is_active' => false]);

        // The row is still there, but a pulled product does not show as a dead card.
        $this->withToken($token)->getJson('/api/storefront/v1/favorites')
            ->assertJsonPath('count', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_cannot_favorite_a_deactivated_product(): void
    {
        $this->makeProduct('tee-a', active: false);

        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_cannot_favorite_a_nonexistent_product(): void
    {
        $this->withToken($this->token())
            ->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'ghost'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    public function test_wishlist_carries_product_detail_for_the_tab(): void
    {
        $this->makeProduct('tee-a', price: 1450);
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a']);

        $this->withToken($token)->getJson('/api/storefront/v1/favorites')
            ->assertJsonPath('data.0.slug', 'tee-a')
            ->assertJsonPath('data.0.price', 1450)
            ->assertJsonPath('data.0.price_formatted', '₱1,450')
            ->assertJsonPath('data.0.rating_count', 0);
    }

    public function test_deleting_the_user_clears_their_favorites(): void
    {
        $this->makeProduct('tee-a');
        $token = $this->token();
        $this->withToken($token)->postJson('/api/storefront/v1/favorites/toggle', ['slug' => 'tee-a']);

        \App\Models\Storefront\Customer::firstWhere('email', 'fan@example.com')->delete();

        $this->assertDatabaseCount('storefront_favorites', 0);
    }
}
