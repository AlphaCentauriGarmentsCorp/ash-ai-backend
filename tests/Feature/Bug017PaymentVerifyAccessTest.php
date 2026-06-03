<?php

/**
 * BUG-017 regression test.
 *
 * Run with:
 *   php artisan test --filter=Bug017PaymentVerifyAccessTest
 *
 * Coverage:
 *   1. Finance user with action.verify-payment but WITHOUT portal.csr
 *      can successfully verify a payment (200 + status flipped to verified)
 *   2. CSR user with portal.csr but WITHOUT action.verify-payment
 *      receives a 403 on the same endpoint
 *   3. User with NEITHER permission receives 403
 *
 * Why this test exists:
 *   The original Phase 6-A routes stacked `permission:portal.csr` at the
 *   group level AND `permission:action.verify-payment` on the verify route.
 *   Spatie's PermissionMiddleware AND's stacked permissions, which broke
 *   the Finance flow. The fix split verify into a second group gated
 *   only on action.verify-payment. This test ensures the split stays.
 */

use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',

        'csr_activity_logs',
        'order_payments',
        'payment_methods',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('username')->nullable()->unique();
        $t->string('email')->unique();
        $t->string('password')->default('x');
        $t->text('domain_role')->nullable();
        $t->text('domain_access')->nullable();
        $t->timestamps();
        // User model uses SoftDeletes — verify() eager-loads verifiedBy/
        // uploadedBy, which query users WHERE deleted_at IS NULL.
        $t->softDeletes();
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->decimal('subtotal', 10, 2)->default(0);
        $t->decimal('grand_total', 10, 2)->default(0);
        $t->string('status')->default('new');
        $t->string('workflow_status', 32)->default('inquiry');
        $t->timestamp('delayed_at')->nullable();
        $t->string('priority', 16)->default('normal');
        $t->boolean('rush_order')->default(false);
        $t->unsignedBigInteger('assigned_csr_user_id')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('payment_methods', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->text('description')->nullable();
        $t->timestamps();
    });

    Schema::create('order_payments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('payment_type', 16);
        $t->decimal('amount', 10, 2);
        $t->unsignedBigInteger('payment_method_id')->nullable();
        $t->string('reference_number')->nullable();
        $t->string('proof_path', 255)->nullable();
        $t->string('status', 24)->default('waiting');
        $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
        $t->timestamp('uploaded_at')->nullable();
        $t->unsignedBigInteger('verified_by_user_id')->nullable();
        $t->timestamp('verified_at')->nullable();
        $t->text('rejection_reason')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('csr_activity_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('action', 64);
        $t->string('subject_type')->nullable();
        $t->unsignedBigInteger('subject_id')->nullable();
        $t->unsignedBigInteger('order_id')->nullable();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('summary', 255)->nullable();
        $t->json('data')->nullable();
        $t->timestamp('created_at')->useCurrent();
    });

    // Spatie tables
    Schema::create('permissions', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name');
        $t->timestamps();
    });
    Schema::create('roles', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name');
        $t->timestamps();
    });
    Schema::create('model_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['permission_id', 'model_id', 'model_type']);
    });
    Schema::create('model_has_roles', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });
    Schema::create('role_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->unsignedBigInteger('role_id');
        $t->primary(['permission_id', 'role_id']);
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('public');
});

afterEach(function () {
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',
        'csr_activity_logs',
        'order_payments',
        'payment_methods',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Fixture builders ────────────────────────────────────────────────────

function bug017MakeUser(array $permissionNames): \App\Models\User
{
    $user = \App\Models\User::create([
        'name'          => 'User ' . uniqid(),
        'username'      => 'u_' . uniqid(),
        'email'         => 'u_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['finance'],
    ]);

    foreach ($permissionNames as $pname) {
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name'       => $pname,
            'guard_name' => 'web',
        ]);
    }
    if ($permissionNames !== []) {
        $user->givePermissionTo($permissionNames);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    return $user;
}

function bug017MakePaymentInForVerification(): OrderPayment
{
    $order = Order::create([
        'po_code'         => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_name'     => 'BUG017 Client',
        'client_brand'    => 'BUG017',
        'workflow_status' => 'in_progress',
    ]);

    return OrderPayment::create([
        'order_id'             => $order->id,
        'payment_type'         => 'down_payment',
        'amount'               => 5000.00,
        'status'               => OrderPayment::STATUS_FOR_VERIFICATION,
        'uploaded_by_user_id'  => null,
        'uploaded_at'          => now(),
    ]);
}

// ── Tests ───────────────────────────────────────────────────────────────

test('BUG-017: Finance with action.verify-payment but NOT portal.csr can verify', function () {
    $finance = bug017MakeUser(['action.verify-payment']);
    expect($finance->can('portal.csr'))->toBeFalse();
    expect($finance->can('action.verify-payment'))->toBeTrue();

    $payment = bug017MakePaymentInForVerification();

    $this->actingAs($finance, 'sanctum');

    $response = $this->patchJson("/api/v2/csr/payments/{$payment->id}/verify", [
        'decision' => 'verified',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.status', 'verified');
    $response->assertJsonPath('data.verified_by_user_id', $finance->id);
});

test('BUG-017: CSR with portal.csr but NOT action.verify-payment is rejected (403)', function () {
    $csr = bug017MakeUser(['portal.csr']);
    expect($csr->can('portal.csr'))->toBeTrue();
    expect($csr->can('action.verify-payment'))->toBeFalse();

    $payment = bug017MakePaymentInForVerification();

    $this->actingAs($csr, 'sanctum');

    $response = $this->patchJson("/api/v2/csr/payments/{$payment->id}/verify", [
        'decision' => 'verified',
    ]);

    $response->assertStatus(403);
});

test('BUG-017: User with NEITHER permission is rejected (403)', function () {
    $nobody = bug017MakeUser([]);
    expect($nobody->can('portal.csr'))->toBeFalse();
    expect($nobody->can('action.verify-payment'))->toBeFalse();

    $payment = bug017MakePaymentInForVerification();

    $this->actingAs($nobody, 'sanctum');

    $response = $this->patchJson("/api/v2/csr/payments/{$payment->id}/verify", [
        'decision' => 'verified',
    ]);

    $response->assertStatus(403);
});

test('BUG-017: Finance with BOTH permissions (e.g. via workaround grant) can still verify', function () {
    // Sanity test: granting portal.csr on top of action.verify-payment
    // (the Option A workaround) does not break verify behavior. This
    // ensures Josh's current dev DB state stays functional during transition.
    $hybrid = bug017MakeUser(['portal.csr', 'action.verify-payment']);

    $payment = bug017MakePaymentInForVerification();

    $this->actingAs($hybrid, 'sanctum');

    $response = $this->patchJson("/api/v2/csr/payments/{$payment->id}/verify", [
        'decision' => 'verified',
    ]);

    $response->assertStatus(200);
});