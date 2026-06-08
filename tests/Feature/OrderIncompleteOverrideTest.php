<?php

use App\Models\Client;
use App\Models\CsrActivityLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Change 11 — superadmin "save anyway" override.
 *
 * Covers: a superadmin can persist an order missing SOFT fields (flagged
 * Incomplete + audited); a non-superadmin cannot override; the hard floor
 * (>=1 line item) is never bypassable; a complete order is not flagged.
 *
 * Uses real migrations (RefreshDatabase) so the new is_incomplete /
 * incomplete_fields columns and the full create path (PO items, stages,
 * QR codes) exercise end to end. Storage::fake handles the QR/barcode PNGs.
 */

uses(RefreshDatabase::class);

function makeOrderUser(array $roleNames, array $permissionNames = []): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($roleNames as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    foreach ($permissionNames as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }

    $user = User::factory()->create([
        'username'      => 'ash_' . uniqid(),
        'domain_role'   => $roleNames,
        'domain_access' => ['ash'],
    ]);
    $user->syncRoles($roleNames);

    if ($permissionNames !== []) {
        $user->givePermissionTo($permissionNames);
    }

    return $user;
}

function makeOrderClient(): Client
{
    return Client::create([
        'name'           => 'Acme Apparel',
        'email'          => 'acme@example.com',
        'contact_number' => '09170000000',
    ]);
}

function baseOrderPayload(Client $client, array $overrides = []): array
{
    return array_merge([
        'client_id'    => $client->id,
        'client_name'  => 'Acme Apparel',
        'client_brand' => 'Acme Brand',
        'items_json'   => [
            ['size' => 'M', 'quantity' => 10, 'unit_price' => 250],
        ],
        'subtotal'    => 2500,
        'grand_total' => 2500,
    ], $overrides);
}

beforeEach(function () {
    Storage::fake('public');
});

it('lets a superadmin save an incomplete order and flags + audits it', function () {
    $super  = makeOrderUser(['superadmin']);
    $client = makeOrderClient();

    $this->actingAs($super, 'sanctum');

    $res = $this->postJson('/api/v2/orders', baseOrderPayload($client, [
        'override_incomplete' => true,
        'incomplete_fields'   => ['deadline', 'brand', 'priority'],
    ]))->assertSuccessful();

    expect($res->json('data.is_incomplete'))->toBeTrue();
    expect($res->json('data.incomplete_fields'))->toEqual(['deadline', 'brand', 'priority']);

    $order = Order::first();
    expect($order->is_incomplete)->toBeTrue();
    expect($order->incomplete_fields)->toEqual(['deadline', 'brand', 'priority']);

    $log = CsrActivityLog::where('action', 'order.saved_incomplete')->first();
    expect($log)->not->toBeNull();
    expect($log->order_id)->toBe($order->id);
    expect($log->user_id)->toBe($super->id);
    expect($log->data['incomplete_fields'] ?? null)->toEqual(['deadline', 'brand', 'priority']);
});

it('blocks a non-superadmin from overriding', function () {
    $csr    = makeOrderUser(['csr'], ['access.orders']);
    $client = makeOrderClient();

    $this->actingAs($csr, 'sanctum');

    $this->postJson('/api/v2/orders', baseOrderPayload($client, [
        'override_incomplete' => true,
        'incomplete_fields'   => ['deadline'],
    ]))
        ->assertStatus(403)
        ->assertJson(['type' => 'business', 'code' => 'ORDER_OVERRIDE_FORBIDDEN']);

    expect(Order::count())->toBe(0);
});

it('enforces the line-item floor even for a superadmin override', function () {
    $super  = makeOrderUser(['superadmin']);
    $client = makeOrderClient();

    $this->actingAs($super, 'sanctum');

    $this->postJson('/api/v2/orders', [
        'client_id'           => $client->id,
        'items_json'          => [],
        'override_incomplete' => true,
        'incomplete_fields'   => ['deadline'],
    ])
        ->assertStatus(422)
        ->assertJson(['type' => 'business', 'code' => 'ORDER_NO_LINE_ITEMS']);

    expect(Order::count())->toBe(0);
});

it('saves a complete order without the incomplete flag', function () {
    $super  = makeOrderUser(['superadmin']);
    $client = makeOrderClient();

    $this->actingAs($super, 'sanctum');

    $res = $this->postJson('/api/v2/orders', baseOrderPayload($client))
        ->assertSuccessful();

    expect($res->json('data.is_incomplete'))->toBeFalse();
    expect(Order::first()->is_incomplete)->toBeFalse();
    expect(CsrActivityLog::where('action', 'order.saved_incomplete')->count())->toBe(0);
});