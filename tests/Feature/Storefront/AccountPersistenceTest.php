<?php

namespace Tests\Feature\Storefront;

use App\Models\Storefront\Address;
use App\Models\Storefront\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $email = 'me@example.com'): string
    {
        return $this->postJson('/api/storefront/v1/auth/register', [
            'name' => 'Me',
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    private function addressPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Me',
            'phone' => '09170000000',
            'street' => '123 Main St',
            'barangay' => 'Bgy 1',
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'postal' => '1000',
        ], $overrides);
    }

    // --- addresses actually reach the database ---------------------------------

    public function test_an_address_is_saved_and_read_back(): void
    {
        $token = $this->token();

        $id = $this->withToken($token)
            ->postJson('/api/storefront/v1/addresses', $this->addressPayload(['is_default_shipping' => true]))
            ->assertStatus(201)
            ->json('data.id');

        $this->assertDatabaseHas('storefront_addresses', ['id' => $id, 'street' => '123 Main St']);

        // The whole point: it survives a fresh request.
        $this->withToken($token)->getJson('/api/storefront/v1/addresses')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.street', '123 Main St');

        $this->withToken($token)->getJson('/api/storefront/v1/account')
            ->assertStatus(200)
            ->assertJsonPath('addresses.0.street', '123 Main St');
    }

    public function test_an_address_can_be_updated_and_deleted(): void
    {
        $token = $this->token();

        $id = $this->withToken($token)
            ->postJson('/api/storefront/v1/addresses', $this->addressPayload())
            ->json('data.id');

        $this->withToken($token)
            ->patchJson("/api/storefront/v1/addresses/$id", ['city' => 'Quezon City'])
            ->assertStatus(200)
            ->assertJsonPath('data.city', 'Quezon City');

        $this->assertDatabaseHas('storefront_addresses', ['id' => $id, 'city' => 'Quezon City']);

        $this->withToken($token)->deleteJson("/api/storefront/v1/addresses/$id")->assertStatus(200);
        $this->assertDatabaseMissing('storefront_addresses', ['id' => $id]);
    }

    public function test_only_one_address_can_be_the_default(): void
    {
        $token = $this->token();

        $first = $this->withToken($token)
            ->postJson('/api/storefront/v1/addresses', $this->addressPayload(['is_default_shipping' => true]))
            ->json('data.id');

        $second = $this->withToken($token)
            ->postJson('/api/storefront/v1/addresses', $this->addressPayload(['is_default_shipping' => true]))
            ->json('data.id');

        $this->assertFalse(Address::find($first)->is_default_shipping, 'the older default should have been released');
        $this->assertTrue(Address::find($second)->is_default_shipping);
    }

    // --- authorization ---------------------------------------------------------

    public function test_a_user_cannot_touch_another_users_address(): void
    {
        $mine = $this->token('alice@example.com');
        $theirs = $this->token('bob@example.com');

        $bobsAddress = $this->withToken($theirs)
            ->postJson('/api/storefront/v1/addresses', $this->addressPayload(['street' => "Bob's Street"]))
            ->json('data.id');

        // Alice guessing Bob's id must learn nothing and change nothing.
        $this->withToken($mine)->patchJson("/api/storefront/v1/addresses/$bobsAddress", ['city' => 'Hacked'])->assertNotFound();
        $this->withToken($mine)->deleteJson("/api/storefront/v1/addresses/$bobsAddress")->assertNotFound();

        $this->assertDatabaseHas('storefront_addresses', ['id' => $bobsAddress, 'street' => "Bob's Street"]);
        $this->withToken($mine)->getJson('/api/storefront/v1/addresses')->assertJsonCount(0, 'data');
    }

    public function test_user_id_cannot_be_mass_assigned_onto_an_address(): void
    {
        $mine = $this->token('alice@example.com');
        $bob = Customer::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'password123']);

        $id = $this->withToken($mine)
            ->postJson('/api/storefront/v1/addresses', $this->addressPayload(['user_id' => $bob->id]))
            ->assertStatus(201)
            ->json('data.id');

        // The address must belong to the caller, not to whoever they named.
        $this->assertNotSame($bob->id, Address::find($id)->user_id);
    }

    // --- profile ---------------------------------------------------------------

    public function test_profile_fields_persist(): void
    {
        $token = $this->token();

        $this->withToken($token)->patchJson('/api/storefront/v1/account', [
            'name' => 'New Name',
            'phone' => '09171234567',
            'birth_date' => '2000-01-15',
            'gender' => 'Rather not say',
        ])->assertStatus(200)->assertJsonPath('user.name', 'New Name');

        $this->withToken($token)->getJson('/api/storefront/v1/account')
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.phone', '09171234567')
            ->assertJsonPath('user.birth_date', '2000-01-15')
            ->assertJsonPath('user.gender', 'Rather not say');
    }

    public function test_changing_email_requires_the_current_password(): void
    {
        $token = $this->token();

        $this->withToken($token)
            ->patchJson('/api/storefront/v1/account', ['email' => 'new@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->withToken($token)
            ->patchJson('/api/storefront/v1/account', ['email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertDatabaseHas('storefront_users', ['email' => 'me@example.com']);

        $this->withToken($token)
            ->patchJson('/api/storefront/v1/account', ['email' => 'new@example.com', 'current_password' => 'password123'])
            ->assertStatus(200);

        $this->assertDatabaseHas('storefront_users', ['email' => 'new@example.com']);
    }

    public function test_changing_password_requires_the_current_one_and_rotates_the_token(): void
    {
        $token = $this->token();

        $this->withToken($token)
            ->patchJson('/api/storefront/v1/account', ['password' => 'brand-new-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $new = $this->withToken($token)->patchJson('/api/storefront/v1/account', [
            'password' => 'brand-new-password',
            'current_password' => 'password123',
        ])->assertStatus(200)->json('token');

        $this->assertTrue(Hash::check('brand-new-password', Customer::first()->password));

        // The old token dies with the password change; the returned one works.
        $this->assertNotNull($new);
        $this->withToken($token)->getJson('/api/storefront/v1/auth/me')->assertStatus(401);
        $this->withToken($new)->getJson('/api/storefront/v1/auth/me')->assertStatus(200);
    }

    public function test_keeping_your_own_email_is_not_a_uniqueness_conflict(): void
    {
        $token = $this->token();

        $this->withToken($token)->patchJson('/api/storefront/v1/account', [
            'email' => 'me@example.com',
            'current_password' => 'password123',
        ])->assertStatus(200);
    }

    public function test_account_endpoints_require_authentication(): void
    {
        $this->patchJson('/api/storefront/v1/account', ['name' => 'X'])->assertStatus(401);
        $this->postJson('/api/storefront/v1/addresses', $this->addressPayload())->assertStatus(401);
        $this->getJson('/api/storefront/v1/addresses')->assertStatus(401);
    }
}
