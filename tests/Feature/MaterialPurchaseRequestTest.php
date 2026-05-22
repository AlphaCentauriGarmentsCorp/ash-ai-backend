<?php

/**
 * Phase 3 — Material Request + Purchase Request service tests.
 *
 * Run with:
 *     php artisan test --filter=MaterialPurchaseRequestTest
 *
 * Same isolation strategy as NotificationServiceTest: build the
 * minimal set of tables we need by hand, seed Spatie roles, and
 * exercise the services + controllers end-to-end.
 *
 * What this tests:
 *   - MR creation: happy path, manager bypass, validation
 *   - MR approve: sufficient stock decrements, insufficient triggers PR
 *   - MR reject: requires reason, sets fields
 *   - PR lifecycle: pending → approved → ordered → received (+ stock)
 *   - One-PR-per-order: second MR appends to existing pending PR
 *   - Notifications fire for create + decision events
 */

use App\Models\Materials;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\MaterialRequestService;
use App\Services\NotificationService;
use App\Services\PurchaseRequestService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------
// Schema bootstrap — same drop-and-recreate strategy used by
// WorkflowEngineTest / NotificationServiceTest.
// ---------------------------------------------------------------------

beforeEach(function () {
    foreach ([
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
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('hashed');
        $t->timestamps();
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

    // Pre-seed every role the services may dispatch notifications to.
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

afterEach(function () {
    foreach ([
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
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function phase3_makeUserWithRole(string $name, string $role): User
{
    $userId = DB::table('users')->insertGetId([
        'name'  => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . '@example.com',
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

function phase3_makeOrderWithStage(string $poCode = 'ASH-TEST-001', ?int $assignedTo = null, string $assignedRole = 'cutter'): Order
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => $poCode,
        'client_brand' => 'TestBrand',
        'workflow_status' => 'cutting',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => 'cutting',
        'sequence' => 1,
        'status' => 'in_progress',
        'assigned_to' => $assignedTo,
        'assigned_role' => $assignedRole,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('orders')->where('id', $orderId)->update(['current_stage_id' => $stageId]);
    return Order::find($orderId);
}

function phase3_makeMaterial(string $name, float $stock, ?int $supplierId = null, float $price = 10.00): Materials
{
    return Materials::create([
        'name' => $name,
        'unit' => 'm',
        'price' => $price,
        'stock_on_hand' => $stock,
        'supplier_id' => $supplierId,
    ]);
}

function phase3_makeService(): MaterialRequestService
{
    return new MaterialRequestService(
        new NotificationService(),
        new PurchaseRequestService(new NotificationService()),
    );
}

// ---------------------------------------------------------------------
// MR creation
// ---------------------------------------------------------------------

it('creates an MR for the assigned user with snapshotted stock', function () {
    $cutter = phase3_makeUserWithRole('Cutter Bob', 'cutter');
    $order  = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');
    $mat    = phase3_makeMaterial('Red Thread', stock: 10);

    $service = phase3_makeService();
    $mr = $service->create([
        'order_id' => $order->id,
        'reason'   => 'Need thread for cutting',
        'items'    => [[
            'material_id'        => $mat->id,
            'quantity_requested' => 5,
        ]],
    ], $cutter);

    expect($mr)->toBeInstanceOf(MaterialRequest::class);
    expect($mr->status)->toBe(MaterialRequest::STATUS_PENDING);
    expect($mr->mr_code)->toStartWith('MR-');
    expect($mr->order_id)->toBe($order->id);

    expect($mr->items)->toHaveCount(1);
    $item = $mr->items->first();
    expect((float) $item->quantity_requested)->toBe(5.0);
    expect((float) $item->quantity_available)->toBe(10.0);
    expect((float) $item->quantity_short)->toBe(0.0);
});

it('lets a manager bypass stage-restriction', function () {
    $manager = phase3_makeUserWithRole('Big Boss', 'general_manager');
    $order   = phase3_makeOrderWithStage(assignedRole: 'cutter'); // no specific assignment to manager
    $mat     = phase3_makeMaterial('Red Thread', stock: 10);

    $service = phase3_makeService();
    $mr = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 2]],
    ], $manager);

    expect($mr->status)->toBe(MaterialRequest::STATUS_PENDING);
});

it('blocks a non-assigned production user from creating an MR', function () {
    $printer = phase3_makeUserWithRole('Printer Pete', 'printer');
    $order   = phase3_makeOrderWithStage(assignedRole: 'cutter'); // current stage = cutting, not printing
    $mat     = phase3_makeMaterial('Red Thread', stock: 10);

    $service = phase3_makeService();

    expect(fn () => $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 2]],
    ], $printer))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects MR creation with empty items', function () {
    $cutter = phase3_makeUserWithRole('Cutter', 'cutter');
    $order  = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');

    $service = phase3_makeService();
    expect(fn () => $service->create([
        'order_id' => $order->id,
        'items'    => [],
    ], $cutter))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects MR creation with zero quantity', function () {
    $cutter = phase3_makeUserWithRole('Cutter', 'cutter');
    $order  = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');
    $mat    = phase3_makeMaterial('Thread', stock: 10);

    $service = phase3_makeService();
    expect(fn () => $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 0]],
    ], $cutter))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------
// MR approve — sufficient stock
// ---------------------------------------------------------------------

it('approves an MR and decrements stock when sufficient', function () {
    $cutter  = phase3_makeUserWithRole('Cutter', 'cutter');
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');
    $mat     = phase3_makeMaterial('Thread', stock: 100);

    $service = phase3_makeService();
    $mr = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 30]],
    ], $cutter);

    $approved = $service->approve($mr, $manager);

    expect($approved->status)->toBe(MaterialRequest::STATUS_APPROVED);
    expect($approved->approved_by_user_id)->toBe($manager->id);
    expect($approved->purchase_request_id)->toBeNull();

    $mat->refresh();
    expect((float) $mat->stock_on_hand)->toBe(70.0); // 100 - 30
});

// ---------------------------------------------------------------------
// MR approve — insufficient stock auto-spawns a PR
// ---------------------------------------------------------------------

it('auto-spawns a PR when stock is short on approval', function () {
    $cutter  = phase3_makeUserWithRole('Cutter', 'cutter');
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');

    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => 'ThreadCo', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $mat = phase3_makeMaterial('Thread', stock: 5, supplierId: $supplierId, price: 12.50);

    $service = phase3_makeService();
    $mr = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 20]],
    ], $cutter);

    $approved = $service->approve($mr, $manager);

    expect($approved->status)->toBe(MaterialRequest::STATUS_AUTO_PR);
    expect($approved->purchase_request_id)->not->toBeNull();

    // Stock should NOT have been decremented — that happens at PR receive.
    $mat->refresh();
    expect((float) $mat->stock_on_hand)->toBe(5.0);

    // The PR should exist with one line item for the SHORTAGE only (15, not 20).
    $pr = PurchaseRequest::find($approved->purchase_request_id);
    expect($pr)->not->toBeNull();
    expect($pr->status)->toBe(PurchaseRequest::STATUS_PENDING);
    expect($pr->order_id)->toBe($order->id);
    expect($pr->supplier_id)->toBe($supplierId);

    expect($pr->items)->toHaveCount(1);
    expect((float) $pr->items->first()->quantity)->toBe(15.0); // 20 requested - 5 in stock
    expect((float) $pr->items->first()->unit_price)->toBe(12.50);
    expect((float) $pr->total_amount)->toBe(187.50); // 15 * 12.50
});

// ---------------------------------------------------------------------
// "One PR per order" rule — second MR appends to existing pending PR
// ---------------------------------------------------------------------

it('appends short items to existing pending PR on the same order', function () {
    $cutter  = phase3_makeUserWithRole('Cutter', 'cutter');
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');

    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => 'ThreadCo', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $mat1 = phase3_makeMaterial('Red Thread',  stock: 0, supplierId: $supplierId, price: 10);
    $mat2 = phase3_makeMaterial('Blue Thread', stock: 0, supplierId: $supplierId, price: 5);

    $service = phase3_makeService();

    // First MR — only red.
    $mr1 = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat1->id, 'quantity_requested' => 10]],
    ], $cutter);
    $approved1 = $service->approve($mr1, $manager);

    // Second MR — only blue.
    $mr2 = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat2->id, 'quantity_requested' => 8]],
    ], $cutter);
    $approved2 = $service->approve($mr2, $manager);

    // Both MRs should point at the SAME PR (one PR per order).
    expect($approved1->purchase_request_id)->toBe($approved2->purchase_request_id);

    // The shared PR should have 2 line items totalling correctly.
    $pr = PurchaseRequest::find($approved1->purchase_request_id);
    expect($pr->items)->toHaveCount(2);
    expect((float) $pr->total_amount)->toBe(140.0); // (10*10) + (8*5)
});

// ---------------------------------------------------------------------
// MR reject
// ---------------------------------------------------------------------

it('rejects an MR with a required reason and does not move stock', function () {
    $cutter  = phase3_makeUserWithRole('Cutter', 'cutter');
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');
    $mat     = phase3_makeMaterial('Thread', stock: 100);

    $service = phase3_makeService();
    $mr = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 30]],
    ], $cutter);

    $rejected = $service->reject($mr, 'Excessive quantity for this stage', $manager);

    expect($rejected->status)->toBe(MaterialRequest::STATUS_REJECTED);
    expect($rejected->rejection_reason)->toContain('Excessive');
    expect($rejected->approved_by_user_id)->toBe($manager->id);

    $mat->refresh();
    expect((float) $mat->stock_on_hand)->toBe(100.0); // unchanged
});

it('refuses to reject without a reason', function () {
    $cutter  = phase3_makeUserWithRole('Cutter', 'cutter');
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');
    $mat     = phase3_makeMaterial('Thread', stock: 100);

    $service = phase3_makeService();
    $mr = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 10]],
    ], $cutter);

    expect(fn () => $service->reject($mr, '', $manager))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('refuses to approve an already-decided MR', function () {
    $cutter  = phase3_makeUserWithRole('Cutter', 'cutter');
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');
    $mat     = phase3_makeMaterial('Thread', stock: 100);

    $service = phase3_makeService();
    $mr = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 5]],
    ], $cutter);

    $service->approve($mr, $manager);

    expect(fn () => $service->approve($mr->fresh(), $manager))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------
// PR lifecycle
// ---------------------------------------------------------------------

it('walks a PR through approve → ordered → received and increments stock', function () {
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage();
    $supId   = DB::table('suppliers')->insertGetId([
        'name' => 'SupCo', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $mat = phase3_makeMaterial('Cotton', stock: 0, supplierId: $supId, price: 8);

    $pr = PurchaseRequest::create([
        'pr_code'   => 'PR-2026-100001',
        'order_id'  => $order->id,
        'supplier_id' => $supId,
        'status'    => PurchaseRequest::STATUS_PENDING,
        'total_amount' => 800,
    ]);
    PurchaseRequestItem::create([
        'purchase_request_id' => $pr->id,
        'material_id' => $mat->id,
        'quantity'   => 100,
        'unit_price' => 8,
        'line_total' => 800,
        'unit' => 'm',
    ]);

    $svc = new PurchaseRequestService(new NotificationService());

    $approved = $svc->approve($pr->fresh(), $manager);
    expect($approved->status)->toBe(PurchaseRequest::STATUS_APPROVED);
    expect($approved->approved_at)->not->toBeNull();

    $ordered = $svc->markOrdered($approved->fresh(), $manager);
    expect($ordered->status)->toBe(PurchaseRequest::STATUS_ORDERED);
    expect($ordered->ordered_at)->not->toBeNull();

    // Stock still 0 before receiving.
    $mat->refresh();
    expect((float) $mat->stock_on_hand)->toBe(0.0);

    $received = $svc->markReceived($ordered->fresh(), $manager);
    expect($received->status)->toBe(PurchaseRequest::STATUS_RECEIVED);
    expect($received->received_at)->not->toBeNull();

    // Stock incremented by quantity.
    $mat->refresh();
    expect((float) $mat->stock_on_hand)->toBe(100.0);
});

it('refuses to mark received before ordered', function () {
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage();
    $mat     = phase3_makeMaterial('Cotton', stock: 0);

    $pr = PurchaseRequest::create([
        'pr_code'   => 'PR-2026-100002',
        'order_id'  => $order->id,
        'status'    => PurchaseRequest::STATUS_PENDING,
        'total_amount' => 0,
    ]);

    $svc = new PurchaseRequestService(new NotificationService());

    expect(fn () => $svc->markReceived($pr, $manager))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('refuses to cancel a received PR', function () {
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage();

    $pr = PurchaseRequest::create([
        'pr_code'   => 'PR-2026-100003',
        'order_id'  => $order->id,
        'status'    => PurchaseRequest::STATUS_RECEIVED,
        'received_at' => now(),
        'total_amount' => 0,
    ]);

    $svc = new PurchaseRequestService(new NotificationService());

    expect(fn () => $svc->cancel($pr, $manager))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------

it('fires a material_request.created notification for managers + purchasing on create', function () {
    $cutter   = phase3_makeUserWithRole('Cutter', 'cutter');
    $manager  = phase3_makeUserWithRole('Manager', 'general_manager');
    $purchaser = phase3_makeUserWithRole('Purch', 'purchasing');

    $order = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');
    $mat   = phase3_makeMaterial('Thread', stock: 10);

    $service = phase3_makeService();
    $mr = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 5]],
    ], $cutter);

    // Mimic the controller: fire the announcement after create.
    $service->announceCreated($mr);

    // Manager + purchaser get notified, cutter (requester) does not on creation.
    $managerHits = Notification::where('user_id', $manager->id)
        ->where('type', 'material_request.created')->count();
    $purchaserHits = Notification::where('user_id', $purchaser->id)
        ->where('type', 'material_request.created')->count();
    $cutterHits = Notification::where('user_id', $cutter->id)
        ->where('type', 'material_request.created')->count();

    expect($managerHits)->toBe(1);
    expect($purchaserHits)->toBe(1);
    expect($cutterHits)->toBe(0);
});

it('fires a material_request.approved notification to the requester after approval', function () {
    $cutter  = phase3_makeUserWithRole('Cutter', 'cutter');
    $manager = phase3_makeUserWithRole('Manager', 'general_manager');
    $order   = phase3_makeOrderWithStage(assignedTo: $cutter->id, assignedRole: 'cutter');
    $mat     = phase3_makeMaterial('Thread', stock: 100);

    $service = phase3_makeService();
    $mr = $service->create([
        'order_id' => $order->id,
        'items'    => [['material_id' => $mat->id, 'quantity_requested' => 10]],
    ], $cutter);

    $approved = $service->approve($mr, $manager);
    $service->announceDecided($approved);

    $requesterHits = Notification::where('user_id', $cutter->id)
        ->where('type', 'material_request.approved')->count();

    expect($requesterHits)->toBe(1);
});