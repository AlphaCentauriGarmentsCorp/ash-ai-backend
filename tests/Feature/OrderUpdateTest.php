<?php

use App\Models\Client;
use App\Models\CsrActivityLog;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PoItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Issue 1 — Edit Order.
 *
 * Covers: completing an incomplete order clears the flag (+ audits
 * order.completed); a superadmin can re-flag during edit; an order that has
 * entered production (verified payment) can no longer be edited; the PO-item
 * diff preserves SKUs for unchanged sizes and adds/removes as needed; and the
 * previously-dropped deadline/priority/brand now persist.
 *
 * Helpers are uniquely named (updXxx) — Pest loads all test files in one
 * process and the other order tests already declare makeOrderUser/etc.
 */

uses(RefreshDatabase::class);

function updMakeSuperadmin(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
    $user = User::factory()->create([
        'username'      => 'upd_' . uniqid(),
        'domain_role'   => ['superadmin'],
        'domain_access' => ['ash'],
    ]);
    $user->syncRoles(['superadmin']);
    return $user;
}

function updMakeClient(): Client
{
    return Client::create([
        'name'           => 'Upd Apparel',
        'email'          => 'upd@example.com',
        'contact_number' => '09170000000',
    ]);
}

function updPayload(Client $client, array $overrides = []): array
{
    return array_merge([
        'client_id'    => $client->id,
        'client_name'  => 'Upd Apparel',
        'client_brand' => 'Upd Brand',
        'shirt_color'  => 'Black',
        'design_name'  => 'Logo Tee',
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

it('completes an incomplete order and clears the flag', function () {
    $super  = updMakeSuperadmin();
    $client = updMakeClient();
    $this->actingAs($super, 'sanctum');

    // Create it incomplete.
    $this->postJson('/api/v2/orders', updPayload($client, [
        'override_incomplete' => true,
        'incomplete_fields'   => ['deadline', 'brand', 'priority'],
    ]))->assertSuccessful();

    $order = Order::latest('id')->first();
    expect($order->is_incomplete)->toBeTrue();

    // Edit it complete — no override this time, with the formerly-dropped fields.
    $res = $this->putJson("/api/v2/orders/{$order->id}", updPayload($client, [
        'deadline' => '2026-07-01',
        'brand'    => 'Reefer',
        'priority' => 'high',
    ]))->assertSuccessful();

    expect($res->json('data.is_incomplete'))->toBeFalse();
    expect($res->json('data.incomplete_fields'))->toEqual([]);

    $order->refresh();
    expect($order->is_incomplete)->toBeFalse();
    expect($order->incomplete_fields)->toBeNull();

    // Previously-dropped columns now persist.
    expect($order->brand)->toBe('Reefer');
    expect($order->priority)->toBe('high');
    expect((string) $order->deadline?->toDateString())->toBe('2026-07-01');

    expect(CsrActivityLog::where('action', 'order.completed')->count())->toBe(1);
});

it('lets a superadmin re-flag an order during edit', function () {
    $super  = updMakeSuperadmin();
    $client = updMakeClient();
    $this->actingAs($super, 'sanctum');

    $this->postJson('/api/v2/orders', updPayload($client, [
        'override_incomplete' => true,
        'incomplete_fields'   => ['deadline'],
    ]))->assertSuccessful();
    $order = Order::latest('id')->first();

    $res = $this->putJson("/api/v2/orders/{$order->id}", updPayload($client, [
        'override_incomplete' => true,
        'incomplete_fields'   => ['priority', 'brand'],
    ]))->assertSuccessful();

    expect($res->json('data.is_incomplete'))->toBeTrue();
    expect($res->json('data.incomplete_fields'))->toEqual(['priority', 'brand']);
});

it('refuses to edit an order that has entered production', function () {
    $super  = updMakeSuperadmin();
    $client = updMakeClient();
    $this->actingAs($super, 'sanctum');

    $this->postJson('/api/v2/orders', updPayload($client))->assertSuccessful();
    $order = Order::latest('id')->first();

    // Simulate the order having passed its payment gate into production.
    OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 500,
        'status'       => OrderPayment::STATUS_VERIFIED,
    ]);

    $res = $this->putJson("/api/v2/orders/{$order->id}", updPayload($client, [
        'design_name' => 'Changed',
    ]));

    $res->assertStatus(422);
    expect($res->json('code'))->toBe('ORDER_LOCKED_FOR_EDIT');
    expect($res->json('type'))->toBe('business');
});

it('diffs PO items: keeps unchanged size SKUs, adds new, removes gone', function () {
    $super  = updMakeSuperadmin();
    $client = updMakeClient();
    $this->actingAs($super, 'sanctum');

    $this->postJson('/api/v2/orders', updPayload($client))->assertSuccessful();
    $order = Order::latest('id')->first();

    $mSkuBefore = PoItem::where('order_id', $order->id)->where('size', 'M')->value('sku');
    expect($mSkuBefore)->not->toBeNull();

    // Add size L, keep M.
    $this->putJson("/api/v2/orders/{$order->id}", updPayload($client, [
        'items_json' => [
            ['size' => 'M', 'quantity' => 10, 'unit_price' => 250],
            ['size' => 'L', 'quantity' => 5,  'unit_price' => 250],
        ],
    ]))->assertSuccessful();

    $mSkuAfter = PoItem::where('order_id', $order->id)->where('size', 'M')->value('sku');
    expect($mSkuAfter)->toBe($mSkuBefore);                       // preserved
    expect(PoItem::where('order_id', $order->id)->where('size', 'L')->exists())->toBeTrue();

    // Drop M, keep L.
    $this->putJson("/api/v2/orders/{$order->id}", updPayload($client, [
        'items_json' => [
            ['size' => 'L', 'quantity' => 5, 'unit_price' => 250],
        ],
    ]))->assertSuccessful();

    expect(PoItem::where('order_id', $order->id)->where('size', 'M')->exists())->toBeFalse();
    expect(PoItem::where('order_id', $order->id)->where('size', 'L')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Issue 2 — opt-in client-master write-back (decisions: opt-in confirm at
// order save; address + contact ONLY; overwrite-on-confirm; one immutable
// csr_activity_logs row per changed field).
// ---------------------------------------------------------------------------

it('writes back to the client master when sync_client is confirmed', function () {
    $super  = updMakeSuperadmin();
    $client = updMakeClient(); // contact 09170000000, no address parts
    $this->actingAs($super, 'sanctum');

    $this->postJson('/api/v2/orders', updPayload($client))->assertSuccessful();
    $order = Order::latest('id')->first();

    $this->putJson("/api/v2/orders/{$order->id}", updPayload($client, [
        'contact_number'   => '09998887777',
        'street_address'   => '123 Mabini St',
        'barangay_address' => 'Poblacion',
        'city_address'     => 'Quezon City',
        'sync_client'      => true,
    ]))->assertSuccessful();

    $client->refresh();
    expect($client->contact_number)->toBe('09998887777');
    expect($client->street_address)->toBe('123 Mabini St');
    expect($client->barangay)->toBe('Poblacion');
    expect($client->city)->toBe('Quezon City');
    // ClientService recomposes the derived single-line `address`.
    expect($client->address)->toBe('123 Mabini St, Poblacion, Quezon City');

    // One immutable audit row PER changed field, each carrying old → new.
    $logs = CsrActivityLog::where('action', 'client.synced_from_order')
        ->where('client_id', $client->id)
        ->get();
    expect($logs)->toHaveCount(4); // contact, street, barangay, city — province/postal unchanged

    $contactLog = $logs->first(fn ($l) => ($l->data['field'] ?? null) === 'contact_number');
    expect($contactLog)->not->toBeNull();
    expect($contactLog->data['old'])->toBe('09170000000');
    expect($contactLog->data['new'])->toBe('09998887777');
    expect($contactLog->order_id)->toBe($order->id);
    expect($contactLog->user_id)->toBe($super->id);
});

it('leaves the client master untouched without the sync_client flag', function () {
    $super  = updMakeSuperadmin();
    $client = updMakeClient();
    $this->actingAs($super, 'sanctum');

    $this->postJson('/api/v2/orders', updPayload($client))->assertSuccessful();
    $order = Order::latest('id')->first();

    // Same field changes — but NOT confirmed. The order itself updates; the
    // master must not (one-off shipping overrides are legitimate).
    $this->putJson("/api/v2/orders/{$order->id}", updPayload($client, [
        'contact_number' => '09111112222',
        'street_address' => 'Event Venue, SMX Hall 2',
    ]))->assertSuccessful();

    $order->refresh();
    expect($order->contact_number)->toBe('09111112222');

    $client->refresh();
    expect($client->contact_number)->toBe('09170000000');
    expect($client->street_address)->toBeNull();
    expect(
        CsrActivityLog::where('action', 'client.synced_from_order')->count()
    )->toBe(0);
});
