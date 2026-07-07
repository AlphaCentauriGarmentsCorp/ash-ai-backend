<?php

/**
 * SM Rework CP1 — MaterialRequestService explicit stage_id.
 *
 * Run with:
 *   php artisan test --filter=MaterialRequestStageIdTest
 *
 * Coverage:
 *   1. An explicit stage_id attaches the MR to THAT stage — even when the
 *      order's current_stage_id points at a parallel fork — and authorises
 *      the owning role against the requested stage (the screen_making fork).
 *   2. A stage_id that belongs to a different order is rejected (422).
 *   3. Without a stage_id, behaviour is unchanged: the MR attaches to the
 *      order's resolved current stage.
 *
 * Harness mirrors MaterialPurchaseRequestTest (the definitive create()
 * harness). Helper names are prefixed mrsid_ to avoid redeclaring the
 * phase3_* Pest global functions defined there.
 */

use App\Models\MaterialRequest;
use App\Models\Materials;
use App\Models\Order;
use App\Models\User;
use App\Services\MaterialRequestService;
use App\Services\NotificationService;
use App\Services\PurchaseRequestService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$MRSID_TABLES = [
    'purchase_request_items',
    'purchase_requests',
    'material_request_items',
    'material_requests',
    'materials',
    'suppliers',
    'notifications',
    'model_has_roles',
    'roles',
    'order_stages',
    'orders',
    'users',
];

beforeEach(function () use ($MRSID_TABLES) {
    foreach ($MRSID_TABLES as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('hashed');
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_brand')->nullable();
        $t->string('workflow_status', 32)->default('inquiry');
        $t->timestamp('delayed_at')->nullable();
        $t->unsignedBigInteger('current_stage_id')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->text('stage');
        $t->unsignedSmallInteger('sequence')->default(0);
        $t->string('status')->default('pending');
        $t->timestamp('started_at')->nullable();
        $t->timestamp('completed_at')->nullable();
        $t->timestamp('delayed_at')->nullable();
        $t->unsignedBigInteger('assigned_to')->nullable();
        $t->string('assigned_role', 64)->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('roles', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name')->default('web');
        $t->timestamps();
    });

    Schema::create('model_has_roles', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('notifications', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('type', 64);
        $t->string('title');
        $t->text('body')->nullable();
        $t->json('data')->nullable();
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });

    Schema::create('suppliers', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    Schema::create('materials', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('supplier_id')->nullable();
        $t->string('name');
        $t->string('material_type')->nullable();
        $t->string('unit')->nullable();
        $t->decimal('price', 10, 2)->default(0);
        $t->decimal('stock_on_hand', 12, 2)->default(0);
        $t->integer('minimum')->default(0);
        $t->integer('lead')->default(0);
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
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    foreach ([
        'superadmin', 'admin', 'general_manager',
        'csr', 'finance', 'purchasing', 'warehouse_manager',
        'graphic_artist', 'screen_maker', 'sample_maker',
        'cutter', 'printer', 'sewer', 'quality_assurance',
        'packer', 'driver', 'logistics',
    ] as $role) {
        DB::table('roles')->insert([
            'name' => $role, 'guard_name' => 'web',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () use ($MRSID_TABLES) {
    foreach ($MRSID_TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Helpers ─────────────────────────────────────────────────────

function mrsid_makeUserWithRole(string $name, string $role): User
{
    $userId = DB::table('users')->insertGetId([
        'name'  => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . '_' . uniqid() . '@example.com',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $roleId = DB::table('roles')->where('name', $role)->value('id');
    DB::table('model_has_roles')->insert([
        'role_id' => $roleId,
        'model_type' => 'App\\Models\\User',
        'model_id' => $userId,
    ]);

    return User::find($userId);
}

function mrsid_makeMaterial(string $name, float $stock = 100.0): Materials
{
    return Materials::create([
        'name' => $name,
        'unit' => 'm',
        'price' => 10.00,
        'stock_on_hand' => $stock,
    ]);
}

function mrsid_makeService(): MaterialRequestService
{
    return new MaterialRequestService(
        new NotificationService(),
        new PurchaseRequestService(new NotificationService()),
    );
}

/**
 * Order with the two parallel sequence-6 forks:
 *   screen_making (assigned_role screen_maker)  ‖  material_prep_sample
 * current_stage_id deliberately points at material_prep_sample — the
 * "wrong" fork for a screen maker — to prove the explicit stage_id wins.
 *
 * @return array{0:Order,1:int,2:int}  [order, screenStageId, materialPrepStageId]
 */
function mrsid_makeParallelForkOrder(string $poCode = 'ASH-FORK-001'): array
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => $poCode,
        'client_brand' => 'TestBrand',
        'workflow_status' => 'screen_making',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $screenStageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => 'screen_making',
        'sequence' => 6,
        'status' => 'in_progress',
        'assigned_role' => 'screen_maker',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $materialPrepStageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => 'material_prep_sample',
        'sequence' => 6,
        'status' => 'in_progress',
        'assigned_role' => 'warehouse_manager',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Point the order at the parallel fork, NOT the screen stage.
    DB::table('orders')->where('id', $orderId)
        ->update(['current_stage_id' => $materialPrepStageId]);

    return [Order::find($orderId), $screenStageId, $materialPrepStageId];
}

// ── Tests ───────────────────────────────────────────────────────

it('attaches the MR to an explicit stage_id in a parallel fork', function () {
    [$order, $screenStageId, $materialPrepStageId] = mrsid_makeParallelForkOrder();
    $screenMaker = mrsid_makeUserWithRole('Screen Sam', 'screen_maker');
    $mat = mrsid_makeMaterial('Emulsion');

    $mr = mrsid_makeService()->create([
        'order_id' => $order->id,
        'stage_id' => $screenStageId,   // the portal passes its own stage
        'reason'   => 'Need emulsion for screens',
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 3]],
    ], $screenMaker);

    // The MR is pinned to the screen stage, not the order's current fork.
    expect((int) $mr->stage_id)->toBe($screenStageId);
    expect((int) $mr->stage_id)->not->toBe($materialPrepStageId);
    expect($mr->status)->toBe(MaterialRequest::STATUS_PENDING);
});

it('rejects a stage_id that belongs to a different order', function () {
    [$order] = mrsid_makeParallelForkOrder('ASH-FORK-A');

    // A second, unrelated order with its own stage.
    [$otherOrder, $otherScreenStageId] = mrsid_makeParallelForkOrder('ASH-FORK-B');

    $manager = mrsid_makeUserWithRole('Boss Bea', 'general_manager');
    $mat = mrsid_makeMaterial('Emulsion');

    expect(fn () => mrsid_makeService()->create([
        'order_id' => $order->id,
        'stage_id' => $otherScreenStageId,  // foreign stage
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 1]],
    ], $manager))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('falls back to the current stage when no stage_id is given', function () {
    [$order, $screenStageId, $materialPrepStageId] = mrsid_makeParallelForkOrder();
    $manager = mrsid_makeUserWithRole('Boss Ben', 'general_manager');
    $mat = mrsid_makeMaterial('Emulsion');

    $mr = mrsid_makeService()->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 2]],
    ], $manager);

    // Unchanged behaviour: attaches to the order's resolved current stage.
    expect((int) $mr->stage_id)->toBe($materialPrepStageId);
});
