<?php

use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * OrderTrashTest — soft-delete recovery for orders (the "Show deleted"
 * toggle on the All Orders page). Exercises the three new endpoints through
 * the real routing + middleware stack:
 *
 *   GET    /api/v2/orders/deleted        list trashed orders
 *   PATCH  /api/v2/orders/{id}/restore   un-delete (clears deleted_at)
 *   DELETE /api/v2/orders/{id}/force     permanent delete (trashed only)
 *
 * Harness mirrors FabricTypeTest: hand-built minimal schema (no
 * RefreshDatabase), Spatie permissions, actingAs sanctum. The ~27 real
 * child tables are NOT in this throwaway schema, so cascade-on-hard-delete
 * is verified separately against the live MySQL DB in APPLY-AND-VERIFY.md.
 */
beforeEach(function () {
    foreach ([
        'order_payments', 'order_stages', 'orders',
        'apparel_types', 'pattern_types', 'print_methods',
        'model_has_permissions', 'role_has_permissions', 'model_has_roles',
        'permissions', 'roles', 'users',
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
        $t->softDeletes();
    });

    Schema::create('roles', function (Blueprint $t) {
        $t->id(); $t->string('name'); $t->string('guard_name')->default('web'); $t->timestamps();
    });
    Schema::create('permissions', function (Blueprint $t) {
        $t->id(); $t->string('name'); $t->string('guard_name')->default('web'); $t->timestamps();
    });
    Schema::create('model_has_roles', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id'); $t->string('model_type'); $t->unsignedBigInteger('model_id');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });
    Schema::create('model_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id'); $t->string('model_type'); $t->unsignedBigInteger('model_id');
        $t->primary(['permission_id', 'model_id', 'model_type']);
    });
    Schema::create('role_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id'); $t->unsignedBigInteger('role_id');
        $t->primary(['permission_id', 'role_id']);
    });

    // Lookup tables eager-loaded by index() / deletedIndex().
    foreach (['apparel_types', 'pattern_types', 'print_methods'] as $lookup) {
        Schema::create($lookup, function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->timestamps();
        });
    }

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->unsignedBigInteger('apparel_type_id')->nullable();
        $t->unsignedBigInteger('pattern_type_id')->nullable();
        $t->unsignedBigInteger('print_method_id')->nullable();
        $t->unsignedBigInteger('current_stage_id')->nullable();
        $t->unsignedBigInteger('assigned_csr_user_id')->nullable();
        $t->string('status')->nullable();
        $t->string('workflow_status')->nullable();
        $t->decimal('grand_total', 12, 2)->default(0);
        $t->boolean('is_incomplete')->default(false);
        $t->timestamps();
        $t->softDeletes();
    });

    // withCount('orderStages') + currentStage belongsTo(current_stage_id).
    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('stage')->nullable();
        $t->integer('sequence')->nullable();
        $t->string('status')->nullable();
        $t->timestamps();
    });

    // withCount('payments' where status = verified).
    Schema::create('order_payments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('status')->nullable();
        $t->decimal('amount', 12, 2)->default(0);
        $t->timestamps();
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function trashMakeUser(array $perms = []): \App\Models\User
{
    $u = \App\Models\User::create([
        'name'          => 'U ' . uniqid(),
        'username'      => 'u_' . uniqid(),
        'email'         => 'u_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['superadmin'],
    ]);
    foreach ($perms as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        $u->givePermissionTo($p);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    return $u;
}

function trashMakeOrder(string $po): Order
{
    return Order::create([
        'po_code'     => $po,
        'client_name' => 'Test Client',
        'grand_total' => 1000,
    ]);
}

test('a deleted order appears in the deleted list and drops from the main index', function () {
    $keep    = trashMakeOrder('ASH-2026-000001');
    $trashed = trashMakeOrder('ASH-2026-000002');

    $this->actingAs(trashMakeUser(['access.orders']), 'sanctum');

    // Soft-delete via the existing endpoint (the real user flow).
    $this->deleteJson("/api/v2/orders/{$trashed->id}")->assertSuccessful();

    // Main list shows only the kept order.
    $index = $this->getJson('/api/v2/orders')->assertStatus(200)->json('data');
    expect(collect($index)->pluck('id')->all())->toContain($keep->id);
    expect(collect($index)->pluck('id')->all())->not->toContain($trashed->id);

    // Deleted list shows only the trashed order, with deleted_at populated.
    $deleted = $this->getJson('/api/v2/orders/deleted')->assertStatus(200)->json('data');
    expect(collect($deleted)->pluck('id')->all())->toContain($trashed->id);
    expect(collect($deleted)->pluck('id')->all())->not->toContain($keep->id);
    expect($deleted[0]['deleted_at'])->not->toBeNull();
});

test('a trashed order can be restored', function () {
    $order = trashMakeOrder('ASH-2026-000003');
    $order->delete();

    $this->actingAs(trashMakeUser(['access.orders']), 'sanctum');

    $this->patchJson("/api/v2/orders/{$order->id}/restore")
        ->assertStatus(200)
        ->assertJsonPath('id', $order->id);

    // Back in the live table, gone from the trash.
    expect(Order::find($order->id))->not->toBeNull();
    expect($this->getJson('/api/v2/orders/deleted')->json('data'))->toBeEmpty();
});

test('a trashed order can be permanently deleted', function () {
    $order = trashMakeOrder('ASH-2026-000004');
    $order->delete();

    $this->actingAs(trashMakeUser(['access.orders']), 'sanctum');

    $this->deleteJson("/api/v2/orders/{$order->id}/force")
        ->assertStatus(200)
        ->assertJsonPath('id', $order->id);

    // Gone entirely — not even withTrashed() sees it.
    expect(Order::withTrashed()->find($order->id))->toBeNull();
});

test('a live order cannot be permanently deleted (must be trashed first)', function () {
    $order = trashMakeOrder('ASH-2026-000005');   // NOT deleted

    $this->actingAs(trashMakeUser(['access.orders']), 'sanctum');

    $this->deleteJson("/api/v2/orders/{$order->id}/force")->assertStatus(404);

    // Still alive.
    expect(Order::find($order->id))->not->toBeNull();
});

test('restoring an order that is not in the trash 404s', function () {
    $order = trashMakeOrder('ASH-2026-000006');   // NOT deleted

    $this->actingAs(trashMakeUser(['access.orders']), 'sanctum');

    $this->patchJson("/api/v2/orders/{$order->id}/restore")->assertStatus(404);
});

test('the recovery endpoints reject users without access.orders (403)', function () {
    $order = trashMakeOrder('ASH-2026-000007');
    $order->delete();

    $this->actingAs(trashMakeUser([]), 'sanctum');   // no permissions

    $this->getJson('/api/v2/orders/deleted')->assertStatus(403);
    $this->patchJson("/api/v2/orders/{$order->id}/restore")->assertStatus(403);
    $this->deleteJson("/api/v2/orders/{$order->id}/force")->assertStatus(403);
});
