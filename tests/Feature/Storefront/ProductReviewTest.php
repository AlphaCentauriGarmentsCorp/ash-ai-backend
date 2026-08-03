<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Product;
use App\Models\Storefront\ProductReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(string $slug = 'test-tee', bool $active = true): Product
    {
        $product = Product::create([
            'slug' => $slug,
            'name' => 'Test Tee',
            'audience' => 'unisex',
            'type' => 'tee',
            'price' => 1200,
            'is_active' => $active,
            'sort' => 1,
        ]);

        $product->variants()->create(['size' => 'M', 'sku' => strtoupper($slug).'-M', 'on_hand' => 20]);

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

    /**
     * A genuinely signed-out request.
     *
     * withToken() sets a PERSISTENT default header on the test client, so a plain
     * $this->getJson() after any withToken() still carries that token — which
     * quietly turned every "a stranger sees it" assertion into a signed-in one.
     * Flush first, or the test proves nothing.
     */
    private function asGuest(): static
    {
        $this->flushHeaders();

        // Dropping the header is not enough. The middleware calls
        // Auth::guard('storefront')->setUser(), which populates the resolved guard —
        // and the container survives between requests inside one test, so the guard
        // stays signed in even with no token. A real request boots a fresh container,
        // so this is a test artifact, not a production leak; forgetting the guards
        // reproduces that fresh state (forgetGuards() clears every resolved guard,
        // storefront included).
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /** Actually buy it, through the real checkout — not by faking an order row. */
    private function buy(string $token, string $slug = 'test-tee', string $email = 'buyer@example.com'): void
    {
        $this->withToken($token)->postJson('/api/storefront/v1/orders', [
            'email' => $email,
            'ship_to_name' => 'Juan dela Cruz',
            'phone' => '09170000001',
            'street' => '1 Main St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'shipping_method' => 'golocal',
            'payment_method' => 'gcash',
            'items' => [['slug' => $slug, 'size' => 'M', 'qty' => 1]],
        ])->assertStatus(201);
    }

    // --- condition 1: signed in ------------------------------------------------

    public function test_a_signed_out_visitor_cannot_review(): void
    {
        $this->makeProduct();

        $this->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 5])
            ->assertStatus(401);

        $this->assertSame(0, ProductReview::count());
    }

    public function test_a_signed_out_visitor_is_told_they_cannot_review(): void
    {
        $this->makeProduct();

        $this->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertStatus(200)
            ->assertJsonPath('viewer.signed_in', false)
            ->assertJsonPath('viewer.purchased', false)
            ->assertJsonPath('viewer.can_review', false);
    }

    // --- condition 2: has purchased --------------------------------------------

    public function test_a_signed_in_user_who_has_not_bought_it_cannot_review(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)
            ->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 5])
            ->assertStatus(403);

        $this->assertSame(0, ProductReview::count());
    }

    public function test_a_signed_in_user_who_has_not_bought_it_is_told_so(): void
    {
        $this->makeProduct();

        $this->withToken($this->token())
            ->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertJsonPath('viewer.signed_in', true)
            ->assertJsonPath('viewer.purchased', false)
            ->assertJsonPath('viewer.can_review', false);
    }

    public function test_buying_a_DIFFERENT_product_does_not_unlock_this_one(): void
    {
        $this->makeProduct();
        $this->makeProduct(slug: 'other-tee');
        $token = $this->token();

        $this->buy($token, 'other-tee');

        $this->withToken($token)->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertJsonPath('viewer.purchased', false)
            ->assertJsonPath('viewer.can_review', false);

        $this->withToken($token)
            ->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 5])
            ->assertStatus(403);
    }

    public function test_someone_elses_purchase_does_not_unlock_it_for_you(): void
    {
        $this->makeProduct();
        $buyer = $this->token('buyer@example.com');
        $stranger = $this->token('stranger@example.com', 'Ana Cruz');

        $this->buy($buyer);

        $this->withToken($stranger)->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertJsonPath('viewer.purchased', false)
            ->assertJsonPath('viewer.can_review', false);

        $this->withToken($stranger)
            ->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 1])
            ->assertStatus(403);
    }

    // --- the happy path --------------------------------------------------------

    public function test_a_buyer_can_review_and_it_becomes_public(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $this->buy($token);

        $this->withToken($token)->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertJsonPath('viewer.purchased', true)
            ->assertJsonPath('viewer.can_review', true);

        $this->withToken($token)
            ->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 5, 'body' => 'Heavyweight and worth it.'])
            ->assertStatus(201)
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.average', 5)
            ->assertJsonPath('reviews.0.rating', 5);

        $this->assertDatabaseHas('storefront_product_reviews', ['rating' => 5, 'body' => 'Heavyweight and worth it.']);

        // The whole point of "visible to all": a stranger with no account sees it.
        $this->asGuest()->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertStatus(200)
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.average', 5)
            ->assertJsonPath('reviews.0.body', 'Heavyweight and worth it.')
            ->assertJsonPath('reviews.0.verified_purchase', true)
            // …and is correctly told they may not add one.
            ->assertJsonPath('viewer.signed_in', false)
            ->assertJsonPath('viewer.can_review', false);
    }

    public function test_cash_on_delivery_counts_as_a_purchase(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/orders', [
            'email' => 'buyer@example.com',
            'ship_to_name' => 'Juan dela Cruz',
            'phone' => '09170000001',
            'street' => '1 Main St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'shipping_method' => 'golocal',
            'payment_method' => 'cod',
            'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 1]],
        ])->assertStatus(201);

        // They went through checkout and committed to buying it, even though the
        // money has not moved yet.
        $this->withToken($token)->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertJsonPath('viewer.can_review', true);
    }

    public function test_a_declined_order_does_not_unlock_reviewing(): void
    {
        $this->makeProduct();
        $token = $this->token();

        $this->withToken($token)->postJson('/api/storefront/v1/orders', [
            'email' => 'buyer@example.com',
            'ship_to_name' => 'Juan dela Cruz',
            'phone' => '09170000001',
            'street' => '1 Main St',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'shipping_method' => 'golocal',
            'payment_method' => 'gcash',
            'simulate' => 'fail',
            'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 1]],
        ])->assertStatus(402);

        $this->withToken($token)->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertJsonPath('viewer.purchased', false)
            ->assertJsonPath('viewer.can_review', false);
    }

    // --- one per person --------------------------------------------------------

    public function test_reviewing_twice_updates_rather_than_duplicates(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $this->buy($token);

        $this->withToken($token)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 5])->assertStatus(201);
        $this->withToken($token)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 2, 'body' => 'Shrank in the wash.'])
            ->assertStatus(200)
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.average', 2);

        $this->assertSame(1, ProductReview::count());
        $this->assertDatabaseHas('storefront_product_reviews', ['rating' => 2, 'body' => 'Shrank in the wash.']);
    }

    public function test_a_buyer_can_delete_their_own_review(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $this->buy($token);

        $this->withToken($token)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 4]);
        $this->withToken($token)->deleteJson('/api/storefront/v1/products/test-tee/reviews/mine')
            ->assertStatus(200)
            ->assertJsonPath('summary.count', 0);

        $this->assertSame(0, ProductReview::count());
    }

    public function test_deleting_only_removes_your_own_review(): void
    {
        $this->makeProduct();
        $alice = $this->token('alice@example.com', 'Alice Reyes');
        $bob = $this->token('bob@example.com', 'Bob Santos');
        $this->buy($alice, 'test-tee', 'alice@example.com');
        $this->buy($bob, 'test-tee', 'bob@example.com');

        $this->withToken($alice)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 5]);
        $this->withToken($bob)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 1]);

        $this->withToken($alice)->deleteJson('/api/storefront/v1/products/test-tee/reviews/mine')->assertStatus(200);

        $this->assertSame(1, ProductReview::count());
        $this->assertDatabaseHas('storefront_product_reviews', ['rating' => 1]);
    }

    // --- summary + validation --------------------------------------------------

    public function test_average_and_breakdown_are_computed(): void
    {
        $this->makeProduct();
        foreach ([['a@example.com', 5], ['b@example.com', 4], ['c@example.com', 2]] as [$email, $rating]) {
            $t = $this->token($email, 'User '.$rating);
            $this->buy($t, 'test-tee', $email);
            $this->withToken($t)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => $rating]);
        }

        $this->asGuest()->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertJsonPath('summary.count', 3)
            ->assertJsonPath('summary.average', 3.7)   // (5+4+2)/3 = 3.666 -> 3.7
            ->assertJsonPath('summary.breakdown.5', 1)
            ->assertJsonPath('summary.breakdown.4', 1)
            ->assertJsonPath('summary.breakdown.3', 0)
            ->assertJsonPath('summary.breakdown.2', 1)
            ->assertJsonPath('summary.breakdown.1', 0);
    }

    public function test_rating_must_be_one_to_five(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $this->buy($token);

        foreach ([0, 6, -1] as $bad) {
            $this->withToken($token)
                ->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('rating');
        }

        $this->assertSame(0, ProductReview::count());
    }

    public function test_empty_product_reports_no_ratings(): void
    {
        $this->makeProduct();

        $this->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertStatus(200)
            ->assertJsonPath('summary.count', 0)
            ->assertJsonPath('summary.average', null)
            ->assertJsonCount(0, 'reviews');
    }

    public function test_reviews_for_a_deactivated_product_are_404(): void
    {
        $this->makeProduct(active: false);

        $this->getJson('/api/storefront/v1/products/test-tee/reviews')->assertStatus(404);
    }

    // --- privacy ---------------------------------------------------------------

    public function test_public_reviews_never_leak_the_reviewers_email_or_id(): void
    {
        $this->makeProduct();
        $token = $this->token('buyer@example.com', 'Juan dela Cruz');
        $this->buy($token);
        $this->withToken($token)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 5]);

        $response = $this->asGuest()->getJson('/api/storefront/v1/products/test-tee/reviews');

        $response->assertJsonPath('reviews.0.author', 'Juan C.');
        $body = $response->getContent();
        $this->assertStringNotContainsString('buyer@example.com', $body);
        $this->assertStringNotContainsString('user_id', $body);
    }

    public function test_mine_flag_marks_your_own_review_only(): void
    {
        $this->makeProduct();
        $alice = $this->token('alice@example.com', 'Alice Reyes');
        $bob = $this->token('bob@example.com', 'Bob Santos');
        $this->buy($alice, 'test-tee', 'alice@example.com');
        $this->buy($bob, 'test-tee', 'bob@example.com');

        $this->withToken($alice)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 5]);
        $this->withToken($bob)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 3]);

        // Bob reviewed last, so his is first (latest).
        $this->withToken($bob)->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertJsonPath('reviews.0.mine', true)
            ->assertJsonPath('reviews.1.mine', false)
            ->assertJsonPath('viewer.my_review.rating', 3);

        // A signed-out reader owns nothing.
        $this->asGuest()->getJson('/api/storefront/v1/products/test-tee/reviews')
            ->assertJsonPath('reviews.0.mine', false)
            ->assertJsonPath('reviews.1.mine', false)
            ->assertJsonPath('viewer.my_review', null);
    }

    // --- the catalog -----------------------------------------------------------

    public function test_catalog_carries_the_rating_for_everyone(): void
    {
        $this->makeProduct();
        $token = $this->token();
        $this->buy($token);
        $this->withToken($token)->postJson('/api/storefront/v1/products/test-tee/reviews', ['rating' => 4]);

        // Signed out — ratings are public.
        $this->asGuest()->getJson('/api/storefront/v1/products')
            ->assertStatus(200)
            ->assertJsonPath('data.0.rating_count', 1)
            ->assertJsonPath('data.0.rating_average', 4);

        $this->asGuest()->getJson('/api/storefront/v1/products/test-tee')
            ->assertJsonPath('data.rating_count', 1)
            ->assertJsonPath('data.rating_average', 4);
    }
}
