<?php

use App\Exceptions\BusinessRuleException;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\PendingApprovalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Change 11 (gate): an order saved as Incomplete via the superadmin
 * "save anyway" override cannot be approved into production until completed.
 *
 * The block lives in PendingApprovalsService::approve() — the payment-
 * verification approval is the single chokepoint that advances an order past
 * its gate into the production pipeline, so refusing it keeps the order parked.
 *
 * Helpers are uniquely named (gateXxx) because Pest loads every test file into
 * one process and the override test already declares makeOrderUser/etc.
 */

uses(RefreshDatabase::class);

function gateMakeSuperadmin(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'username'      => 'gate_' . uniqid(),
        'domain_role'   => ['superadmin'],
        'domain_access' => ['ash'],
    ]);
    $user->syncRoles(['superadmin']);

    return $user;
}

function gateMakeClient(): Client
{
    return Client::create([
        'name'           => 'Gate Apparel',
        'email'          => 'gate@example.com',
        'contact_number' => '09170000000',
    ]);
}

function gateOrderPayload(Client $client, array $overrides = []): array
{
    return array_merge([
        'client_id'    => $client->id,
        'client_name'  => 'Gate Apparel',
        'client_brand' => 'Gate Brand',
        'shirt_color'  => 'Black',
        'design_name'  => 'Logo Tee',
        'items_json'   => [
            ['size' => 'M', 'quantity' => 10, 'unit_price' => 250],
        ],
        'subtotal'    => 2500,
        'grand_total' => 2500,
    ], $overrides);
}

function gateMakePayment(Order $order): OrderPayment
{
    return OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 500,
        'status'       => OrderPayment::STATUS_FOR_VERIFICATION,
    ]);
}

beforeEach(function () {
    Storage::fake('public');
});

it('blocks approving a payment for an incomplete order', function () {
    $super  = gateMakeSuperadmin();
    $client = gateMakeClient();

    $this->actingAs($super, 'sanctum');
    $this->postJson('/api/v2/orders', gateOrderPayload($client, [
        'override_incomplete' => true,
        'incomplete_fields'   => ['deadline', 'brand'],
    ]))->assertSuccessful();

    $order = Order::latest('id')->first();
    expect($order->is_incomplete)->toBeTrue();

    $payment = gateMakePayment($order);

    $thrown = null;
    try {
        app(PendingApprovalsService::class)->approve($payment->id);
    } catch (BusinessRuleException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->errorCode())->toBe('ORDER_INCOMPLETE');
    expect($thrown->status())->toBe(422);

    // The payment must stay unverified — nothing advanced.
    expect($payment->fresh()->status)->toBe(OrderPayment::STATUS_FOR_VERIFICATION);
});

it('does not fire the incomplete gate for a complete order', function () {
    $super  = gateMakeSuperadmin();
    $client = gateMakeClient();

    $this->actingAs($super, 'sanctum');
    $this->postJson('/api/v2/orders', gateOrderPayload($client))->assertSuccessful();

    $order = Order::latest('id')->first();
    expect($order->is_incomplete)->toBeFalse();

    $payment = gateMakePayment($order);

    // The incomplete gate must NOT fire for a complete order. (verify()/advance
    // may fail for unrelated reasons in this isolated call — we assert only that
    // the ORDER_INCOMPLETE gate itself did not trigger.)
    $firedGate = false;
    try {
        app(PendingApprovalsService::class)->approve($payment->id);
    } catch (BusinessRuleException $e) {
        $firedGate = $e->errorCode() === 'ORDER_INCOMPLETE';
    } catch (\Throwable $e) {
        // unrelated failure — ignore
    }

    expect($firedGate)->toBeFalse();
});
