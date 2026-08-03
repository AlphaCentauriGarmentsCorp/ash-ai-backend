<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(int $stock = 10, bool $active = true): Product
    {
        $product = Product::create([
            'slug' => 'test-tee',
            'name' => 'Test Tee',
            'audience' => 'men',
            'type' => 'tee',
            'price' => 1200,
            'tag' => 'NEW',
            'blurb' => 'A test product',
            'material' => 'Cotton',
            'fit_name' => 'Relaxed Fit',
            'fit_desc' => 'Comfortable fit',
            'is_active' => $active,
            'sort' => 1,
        ]);

        $product->variants()->create(['size' => 'M', 'sku' => 'TEST-TEE-M', 'on_hand' => $stock]);

        return $product;
    }

    private function registerUser(string $email = 'order@example.com'): string
    {
        return $this->postJson('/api/storefront/v1/auth/register', [
            'name' => 'Order User',
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'order@example.com',
            'ship_to_name' => 'Order User',
            'phone' => '09170000001',
            'street' => '123 Main St',
            'barangay' => 'Bgy 1',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'postal' => '1000',
            'shipping_method' => 'golocal',
            'payment_method' => 'gcash',
            'items' => [['slug' => 'test-tee', 'size' => 'M', 'qty' => 2]],
        ], $overrides);
    }

    public function test_user_can_register_and_login(): void
    {
        $response = $this->postJson('/api/storefront/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '09170000000',
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'email']]);

        $this->postJson('/api/storefront/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_user_can_create_an_order(): void
    {
        $this->makeProduct();
        $token = $this->registerUser();

        $this->withToken($token)
            ->postJson('/api/storefront/v1/orders', $this->orderPayload())
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.slug', 'test-tee')
            ->assertJsonPath('data.total', 2400)
            ->assertJsonPath('data.payment_status', 'paid')
            // Brand-new order sits at stage 0 / "Ordered", not "Packed".
            ->assertJsonPath('data.stage', 0)
            ->assertJsonPath('data.stage_label', 'Ordered');
    }
}
