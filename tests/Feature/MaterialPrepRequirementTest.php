<?php

use App\Models\Materials;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\Supplier;
use App\Services\MaterialPrepRequirementService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Spatie\Permission\PermissionRegistrar;

/**
 * Change 18 — Material Prep stage requirement surfacing.
 *  - suggestForOrder aggregates sample-phase logs, scales by order qty,
 *    and best-effort matches each line to a catalog Material.
 *  - saveForOrder reuses the MR create+approve path: in-stock → no PR
 *    ("no purchase needed"); shortfall → Auto-PR.
 */
beforeEach(function () {
    foreach ([
        'purchase_request_items', 'purchase_requests',
        'material_request_items', 'material_requests',
        'stage_ink_logs', 'stage_fabric_logs',
        'po_items', 'materials', 'suppliers',
        'model_has_permissions', 'role_has_permissions', 'model_has_roles',
        'permissions', 'roles', 'order_stages', 'orders', 'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('x');
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->unsignedBigInteger('current_stage_id')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->text('stage');
        $t->string('status')->default('pending');
        $t->integer('sequence')->default(0);
        $t->unsignedBigInteger('assigned_to')->nullable();
        $t->string('assigned_role')->nullable();
        $t->timestamps();
    });

    // Spatie tables
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

    Schema::create('suppliers', function (Blueprint $t) {
        $t->id(); $t->string('name'); $t->timestamps();
    });

    Schema::create('materials', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('supplier_id')->nullable();
        $t->string('name');
        $t->string('material_type');
        $t->string('unit')->nullable();
        $t->decimal('price', 10, 2)->nullable();
        $t->decimal('stock_on_hand', 12, 2)->default(0);
        $t->string('minimum')->nullable();
        $t->string('lead')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('po_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('color')->nullable();
        $t->string('size')->nullable();
        $t->integer('quantity')->default(0);
        $t->timestamps();
    });

    Schema::create('stage_fabric_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id')->nullable();
        $t->string('material_type', 32)->nullable();
        $t->decimal('fabric_used_kg', 12, 2)->default(0);
        $t->decimal('waste_kg', 12, 2)->default(0);
        $t->decimal('usable_remaining_kg', 12, 2)->default(0);
        $t->timestamps();
    });

    Schema::create('stage_ink_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id')->nullable();
        $t->string('ink_color')->nullable();
        $t->decimal('ink_used_kg', 12, 3)->default(0);
        $t->decimal('ink_waste_kg', 12, 3)->default(0);
        $t->decimal('usable_remaining_kg', 12, 3)->default(0);
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('material_requests', function (Blueprint $t) {
        $t->id();
        $t->string('mr_code')->unique();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('stage_id')->nullable();
        $t->unsignedBigInteger('requested_by_user_id');
        $t->string('status', 16)->default('pending');
        $t->text('reason')->nullable();
        $t->text('rejection_reason')->nullable();
        $t->unsignedBigInteger('approved_by_user_id')->nullable();
        $t->timestamp('approved_at')->nullable();
        $t->unsignedBigInteger('purchase_request_id')->nullable();
        $t->timestamps();
    });

    Schema::create('material_request_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('material_request_id');
        $t->unsignedBigInteger('material_id');
        $t->decimal('quantity_requested', 12, 2);
        $t->decimal('quantity_available', 12, 2)->default(0);
        $t->decimal('quantity_short', 12, 2)->default(0);
        $t->string('unit')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('purchase_requests', function (Blueprint $t) {
        $t->id();
        $t->string('pr_code')->unique();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('material_request_id')->nullable();
        $t->unsignedBigInteger('supplier_id')->nullable();
        $t->string('status', 16)->default('pending');
        $t->decimal('total_amount', 12, 2)->default(0);
        $t->text('reason')->nullable();
        $t->unsignedBigInteger('approved_by_user_id')->nullable();
        $t->timestamp('approved_at')->nullable();
        $t->timestamp('ordered_at')->nullable();
        $t->timestamp('received_at')->nullable();
        $t->timestamps();
    });

    Schema::create('purchase_request_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('purchase_request_id');
        $t->unsignedBigInteger('material_id');
        $t->decimal('quantity', 12, 2);
        $t->decimal('unit_price', 12, 2)->default(0);
        $t->decimal('line_total', 12, 2)->default(0);
        $t->string('unit')->nullable();
        $t->timestamps();
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

// ── Helpers ──────────────────────────────────────────────────────────────

function prepMakeManager(): \App\Models\User
{
    $u = \App\Models\User::create([
        'name' => 'Mgr ' . uniqid(), 'email' => 'm_' . uniqid() . '@test.local',
    ]);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $u->assignRole('admin'); // hasAnyRole(['superadmin','admin','general_manager']) → MR create bypass
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    return $u;
}

function prepMakeOrderAtMaterialPrep(int $orderQty = 10): array
{
    $order = Order::create([
        'po_code' => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_brand' => 'PREP', 'client_name' => 'Prep Client',
    ]);

    $cutting = OrderStage::create(['order_id' => $order->id, 'stage' => 'sample_cutting',  'status' => 'completed',   'sequence' => 7]);
    $printing = OrderStage::create(['order_id' => $order->id, 'stage' => 'sample_printing', 'status' => 'completed',   'sequence' => 8]);
    $prep = OrderStage::create(['order_id' => $order->id, 'stage' => 'material_prep_mass', 'status' => 'in_progress', 'sequence' => 13, 'assigned_role' => 'material_prep']);

    // Point the order at the Material Prep stage so resolveCurrentStage is deterministic.
    Order::where('id', $order->id)->update(['current_stage_id' => $prep->id]);

    if ($orderQty > 0) {
        \App\Models\PoItem::create(['order_id' => $order->id, 'quantity' => $orderQty, 'color' => 'Black', 'size' => 'M']);
    }

    return ['order' => $order->fresh(), 'cutting' => $cutting, 'printing' => $printing, 'prep' => $prep];
}

// ── Tests ──────────────────────────────────────────────────────────────────

test('suggestForOrder aggregates sample logs, scales by order qty, matches materials', function () {
    $ctx = prepMakeOrderAtMaterialPrep(10);
    $order = $ctx['order'];

    Supplier::create(['name' => 'Acme']);
    $cotton = Materials::create(['name' => 'Cotton', 'material_type' => 'fabric', 'unit' => 'm', 'stock_on_hand' => 50]);
    $ink    = Materials::create(['name' => 'Black Ink', 'material_type' => 'ink', 'unit' => 'kg', 'stock_on_hand' => 1]);

    \App\Models\StageFabricLog::create([
        'order_id' => $order->id, 'order_stage_id' => $ctx['cutting']->id,
        'material_type' => 'Cotton', 'fabric_used_kg' => 2.0,
    ]);
    \App\Models\StageInkLog::create([
        'order_id' => $order->id, 'order_stage_id' => $ctx['printing']->id,
        'ink_color' => 'Black', 'ink_used_kg' => 0.5,
    ]);

    $rows = app(MaterialPrepRequirementService::class)->suggestForOrder($order);

    expect($rows)->toHaveCount(2);

    $byLabel = collect($rows)->keyBy('label');
    expect($byLabel['Cotton']['suggested_qty'])->toBe(20.0);     // 2.0 × 10
    expect($byLabel['Cotton']['material_id'])->toBe($cotton->id); // exact-name match
    expect($byLabel['Black']['suggested_qty'])->toBe(5.0);        // 0.5 × 10
    expect($byLabel['Black']['material_id'])->toBe($ink->id);     // 'Black' like 'Black Ink'
});

test('saveForOrder with everything in stock → no purchase needed, stock decremented', function () {
    $ctx = prepMakeOrderAtMaterialPrep(10);
    $order = $ctx['order'];
    $mgr = prepMakeManager();

    $cotton = Materials::create(['name' => 'Cotton', 'material_type' => 'fabric', 'unit' => 'm', 'stock_on_hand' => 100]);

    $result = app(MaterialPrepRequirementService::class)->saveForOrder(
        $order,
        [['material_id' => $cotton->id, 'quantity_requested' => 20]],
        $mgr,
    );

    expect($result['purchase_needed'])->toBeFalse();
    expect($result['mr']['status'])->toBe('approved');
    expect($result['pr'])->toBeNull();
    expect((float) $cotton->fresh()->stock_on_hand)->toBe(80.0); // 100 − 20
});

test('saveForOrder with a shortfall → Auto-PR created with correct total', function () {
    $ctx = prepMakeOrderAtMaterialPrep(10);
    $order = $ctx['order'];
    $mgr = prepMakeManager();

    Supplier::create(['name' => 'Acme']);
    $poly = Materials::create(['name' => 'Polyester', 'material_type' => 'fabric', 'unit' => 'm', 'price' => 10, 'stock_on_hand' => 5, 'supplier_id' => 1]);

    $result = app(MaterialPrepRequirementService::class)->saveForOrder(
        $order,
        [['material_id' => $poly->id, 'quantity_requested' => 20]],
        $mgr,
    );

    expect($result['purchase_needed'])->toBeTrue();
    expect($result['mr']['status'])->toBe('auto_pr');
    expect($result['pr'])->not->toBeNull();
    expect($result['pr']['total'])->toBe(150.0); // short 15 × ₱10
    // Stock is NOT decremented for short items (PR receipt handles that later).
    expect((float) $poly->fresh()->stock_on_hand)->toBe(5.0);
});

test('stateForOrder returns a suggestion before save, the saved requirement after', function () {
    $ctx = prepMakeOrderAtMaterialPrep(10);
    $order = $ctx['order'];
    $mgr = prepMakeManager();

    $cotton = Materials::create(['name' => 'Cotton', 'material_type' => 'fabric', 'unit' => 'm', 'stock_on_hand' => 100]);
    \App\Models\StageFabricLog::create([
        'order_id' => $order->id, 'order_stage_id' => $ctx['cutting']->id,
        'material_type' => 'Cotton', 'fabric_used_kg' => 1.0,
    ]);

    $svc = app(MaterialPrepRequirementService::class);

    $before = $svc->stateForOrder($order);
    expect($before['existing'])->toBeNull();
    expect($before['can_save'])->toBeTrue();
    expect($before['suggestion'])->toHaveCount(1);

    $svc->saveForOrder($order, [['material_id' => $cotton->id, 'quantity_requested' => 10]], $mgr);

    $after = $svc->stateForOrder($order->fresh());
    expect($after['existing'])->not->toBeNull();
    expect($after['can_save'])->toBeFalse();
    expect($after['suggestion'])->toBe([]);
});

test('a rejected material request is ignored — order can still be prepared', function () {
    $ctx = prepMakeOrderAtMaterialPrep(10);
    $order = $ctx['order'];

    Materials::create(['name' => 'Cotton', 'material_type' => 'fabric', 'unit' => 'm', 'stock_on_hand' => 100]);
    \App\Models\StageFabricLog::create([
        'order_id' => $order->id, 'order_stage_id' => $ctx['cutting']->id,
        'material_type' => 'Cotton', 'fabric_used_kg' => 1.0,
    ]);

    // An OLD rejected MR attached to the Material Prep stage must NOT be
    // treated as the saved requirement.
    \App\Models\MaterialRequest::create([
        'mr_code' => 'MR-OLD-REJECTED',
        'order_id' => $order->id,
        'stage_id' => $ctx['prep']->id,
        'requested_by_user_id' => 1,
        'status' => 'rejected',
        'reason' => 'old',
    ]);

    $state = app(MaterialPrepRequirementService::class)->stateForOrder($order);

    expect($state['existing'])->toBeNull();   // rejected MR ignored
    expect($state['can_save'])->toBeTrue();
    expect($state['suggestion'])->toHaveCount(1);
});