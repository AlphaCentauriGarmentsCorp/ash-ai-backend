<?php

/**
 * Bundle 2 — Material Prep auto-complete on "all purchase requests received".
 *
 * Run with:
 *   php artisan test --filter=MaterialPrepAutoCompleteTest
 *
 * Like WorkflowEngineTest, these do NOT use RefreshDatabase — they build a
 * minimal isolated schema on in-memory SQLite so OrderStagesService (and the
 * NotificationService it calls) can run markComplete end-to-end, independent of
 * any MySQL-only migration. The schema mirrors WorkflowEngineTest's and adds a
 * minimal purchase_requests table for the readiness check.
 *
 * Covers OrderStagesService::completeMaterialPrepIfReady() and
 * activeMaterialPrepStage():
 *   - sample fork: all PRs received → material_prep_sample completes and the
 *     join (sample_cutting) starts, because screen_making is already done
 *   - one PR still outstanding → no-op, stage stays active
 *   - mass phase: all PRs received → material_prep_mass completes → mass_cutting
 *   - activeMaterialPrepStage finds the active prep stage / returns null when
 *     prep is not the order's current work
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\PurchaseRequest;
use App\Services\OrderStagesService;
use App\Support\WorkflowStages;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'purchase_requests',
        'stage_audit_logs',
        'notifications',
        'model_has_roles',
        'roles',
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->default('hashed');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name')->default('web');
        $table->timestamps();
    });
    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('notifications', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('type', 64);
        $table->string('title');
        $table->text('body')->nullable();
        $table->json('data')->nullable();
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });

    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('po_code')->unique();
        $table->unsignedBigInteger('client_id')->nullable();
        $table->string('client_brand')->nullable();
        $table->date('deadline')->nullable();
        $table->string('priority')->nullable();
        $table->string('brand')->nullable();
        $table->string('courier')->nullable();
        $table->string('method')->nullable();
        $table->string('receiver_name')->nullable();
        $table->string('receiver_contact')->nullable();
        $table->string('address')->nullable();
        $table->string('design_name')->nullable();
        $table->string('apparel_type')->nullable();
        $table->string('pattern_type')->nullable();
        $table->string('service_type')->nullable();
        $table->string('print_method')->nullable();
        $table->string('print_service')->nullable();
        $table->string('size_label')->nullable();
        $table->string('print_label_placement')->nullable();
        $table->string('fabric_type')->nullable();
        $table->string('fabric_supplier')->nullable();
        $table->string('fabric_color')->nullable();
        $table->string('thread_color')->nullable();
        $table->string('ribbing_color')->nullable();
        $table->string('placement_measurements')->nullable();
        $table->text('notes')->nullable();
        $table->text('options')->nullable();
        $table->string('freebie_items')->nullable();
        $table->string('freebie_color')->nullable();
        $table->string('freebie_others')->nullable();
        $table->string('payment_method')->nullable();
        $table->string('payment_plan')->nullable();
        $table->decimal('total_price', 15, 2)->default(0);
        $table->decimal('average_unit_price', 15, 2)->default(0);
        $table->integer('total_quantity')->nullable();
        $table->integer('deposit')->nullable();
        $table->text('design_files')->nullable();
        $table->text('design_mockup')->nullable();
        $table->text('size_label_files')->nullable();
        $table->text('freebies_files')->nullable();
        $table->text('qr_path')->nullable();
        $table->text('barcode_path')->nullable();
        $table->string('status')->default('Pending Approval');
        $table->string('workflow_status', 32)->default('inquiry');
        $table->timestamp('delayed_at')->nullable();
        $table->unsignedBigInteger('current_stage_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('order_stages', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('order_id');
        $table->text('stage');
        $table->unsignedSmallInteger('sequence')->default(0);
        $table->string('status')->default('pending');
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('delayed_at')->nullable();
        $table->unsignedBigInteger('assigned_to')->nullable();
        $table->string('assigned_role', 64)->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();

        $table->index(['order_id', 'sequence']);
        $table->index(['order_id', 'status']);
    });

    Schema::create('stage_audit_logs', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('order_id');
        $table->unsignedBigInteger('order_stage_id');
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('action', 32);
        $table->string('from_status', 32)->nullable();
        $table->string('to_status', 32)->nullable();
        $table->unsignedBigInteger('duration_seconds')->nullable();
        $table->unsignedBigInteger('business_duration_seconds')->nullable();
        $table->text('notes')->nullable();
        $table->timestamp('created_at')->nullable();

        $table->index(['order_id', 'action']);
        $table->index(['order_stage_id', 'action']);
    });

    // Minimal purchase_requests — just the columns completeMaterialPrepIfReady
    // reads (order_id, status) plus what creating a row touches. No SoftDeletes
    // (the model has none).
    Schema::create('purchase_requests', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('order_id')->nullable();
        $table->string('status', 32)->default('pending');
        $table->timestamp('received_at')->nullable();
        $table->timestamps();
    });

    // NotificationService resolves recipients via Spatie role lookups during
    // stage promotion; seed the roles it may query so those lookups don't throw.
    $roles = [
        'superadmin', 'admin', 'general_manager',
        'csr', 'finance', 'purchasing', 'warehouse_manager',
        'graphic_artist', 'screen_maker', 'sample_maker',
        'cutter', 'printer', 'sewer', 'quality_assurance',
        'packer', 'driver', 'logistics',
    ];
    foreach ($roles as $role) {
        DB::table('roles')->insert([
            'name'       => $role,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
        'purchase_requests',
        'stage_audit_logs',
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

function makePrepOrder(): Order
{
    return Order::create([
        'po_code'            => 'ASH-PREP-' . uniqid(),
        'client_brand'       => 'TestBrand',
        'deadline'           => now()->addDays(30),
        'priority'           => 'normal',
        'total_price'        => 1000,
        'average_unit_price' => 100,
        'total_quantity'     => 10,
    ]);
}

/**
 * Insert the full canonical stage set for an order, with statuses overridden
 * per slug (everything not named defaults to pending). Sequence is the stage's
 * canonical tier, exactly as initializeForOrder sets it.
 *
 * @param array<string,string> $statusBySlug
 */
function seedCanonicalStages(Order $order, array $statusBySlug): void
{
    foreach (WorkflowStages::all() as $def) {
        $status = $statusBySlug[$def['key']] ?? OrderStage::STATUS_PENDING;
        OrderStage::create([
            'order_id'     => $order->id,
            'stage'        => $def['key'],
            'sequence'     => $def['seq'],
            'status'       => $status,
            'started_at'   => $status === OrderStage::STATUS_IN_PROGRESS ? now() : null,
            'completed_at' => $status === OrderStage::STATUS_COMPLETED ? now() : null,
        ]);
    }
}

function makePr(Order $order, string $status): PurchaseRequest
{
    return PurchaseRequest::create([
        'order_id'    => $order->id,
        'status'      => $status,
        'received_at' => $status === PurchaseRequest::STATUS_RECEIVED ? now() : null,
    ]);
}

function stageStatus(Order $order, string $slug): ?string
{
    return OrderStage::where('order_id', $order->id)->where('stage', $slug)->value('status');
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('auto-completes material_prep_sample when all PRs are received and joins to sample_cutting', function () {
    $order = makePrepOrder();
    // Sample fork live: screen_making already done, material_prep_sample active.
    seedCanonicalStages($order, [
        'payment_verification_sample' => OrderStage::STATUS_COMPLETED,
        'graphic_artwork'             => OrderStage::STATUS_COMPLETED,
        'screen_making'               => OrderStage::STATUS_COMPLETED,
        'material_prep_sample'        => OrderStage::STATUS_IN_PROGRESS,
    ]);
    makePr($order, PurchaseRequest::STATUS_RECEIVED);
    makePr($order, PurchaseRequest::STATUS_RECEIVED);

    $next = app(OrderStagesService::class)->completeMaterialPrepIfReady($order->id);

    expect($next)->not->toBeNull();
    expect($next->stage)->toBe('sample_cutting');
    expect(stageStatus($order, 'material_prep_sample'))->toBe(OrderStage::STATUS_COMPLETED);
    // Both tier-6 branches done → the join (tier 7) is now in progress.
    expect(stageStatus($order, 'sample_cutting'))->toBe(OrderStage::STATUS_IN_PROGRESS);
});

it('does not complete material_prep_sample while a PR is still outstanding', function () {
    $order = makePrepOrder();
    seedCanonicalStages($order, [
        'payment_verification_sample' => OrderStage::STATUS_COMPLETED,
        'graphic_artwork'             => OrderStage::STATUS_COMPLETED,
        'screen_making'               => OrderStage::STATUS_COMPLETED,
        'material_prep_sample'        => OrderStage::STATUS_IN_PROGRESS,
    ]);
    makePr($order, PurchaseRequest::STATUS_RECEIVED);
    makePr($order, PurchaseRequest::STATUS_ORDERED);   // still outstanding

    $next = app(OrderStagesService::class)->completeMaterialPrepIfReady($order->id);

    expect($next)->toBeNull();
    expect(stageStatus($order, 'material_prep_sample'))->toBe(OrderStage::STATUS_IN_PROGRESS);
    expect(stageStatus($order, 'sample_cutting'))->toBe(OrderStage::STATUS_PENDING);
});

it('treats cancelled PRs as non-blocking', function () {
    $order = makePrepOrder();
    seedCanonicalStages($order, [
        'payment_verification_sample' => OrderStage::STATUS_COMPLETED,
        'graphic_artwork'             => OrderStage::STATUS_COMPLETED,
        'screen_making'               => OrderStage::STATUS_COMPLETED,
        'material_prep_sample'        => OrderStage::STATUS_IN_PROGRESS,
    ]);
    makePr($order, PurchaseRequest::STATUS_RECEIVED);
    makePr($order, PurchaseRequest::STATUS_CANCELLED); // does not block

    $next = app(OrderStagesService::class)->completeMaterialPrepIfReady($order->id);

    expect($next)->not->toBeNull();
    expect(stageStatus($order, 'material_prep_sample'))->toBe(OrderStage::STATUS_COMPLETED);
});

it('auto-completes material_prep_mass when all PRs are received, advancing to mass_cutting', function () {
    $order = makePrepOrder();
    // Whole sample phase done; mass payment cleared; mass prep active.
    seedCanonicalStages($order, [
        'payment_verification_sample' => OrderStage::STATUS_COMPLETED,
        'graphic_artwork'             => OrderStage::STATUS_COMPLETED,
        'screen_making'               => OrderStage::STATUS_COMPLETED,
        'material_prep_sample'        => OrderStage::STATUS_COMPLETED,
        'sample_cutting'              => OrderStage::STATUS_COMPLETED,
        'sample_printing'             => OrderStage::STATUS_COMPLETED,
        'sample_sewing'               => OrderStage::STATUS_COMPLETED,
        'sample_packing'              => OrderStage::STATUS_COMPLETED,
        'sample_approval'             => OrderStage::STATUS_COMPLETED,
        'payment_verification_mass'   => OrderStage::STATUS_COMPLETED,
        'material_prep_mass'          => OrderStage::STATUS_IN_PROGRESS,
    ]);
    makePr($order, PurchaseRequest::STATUS_RECEIVED);

    $next = app(OrderStagesService::class)->completeMaterialPrepIfReady($order->id);

    expect($next)->not->toBeNull();
    expect($next->stage)->toBe('mass_cutting');
    expect(stageStatus($order, 'material_prep_mass'))->toBe(OrderStage::STATUS_COMPLETED);
    expect(stageStatus($order, 'mass_cutting'))->toBe(OrderStage::STATUS_IN_PROGRESS);
});

it('finds the active material-prep stage, and returns null when prep is not current', function () {
    $svc = app(OrderStagesService::class);

    // Active sample prep → found.
    $a = makePrepOrder();
    seedCanonicalStages($a, [
        'payment_verification_sample' => OrderStage::STATUS_COMPLETED,
        'graphic_artwork'             => OrderStage::STATUS_COMPLETED,
        'screen_making'               => OrderStage::STATUS_COMPLETED,
        'material_prep_sample'        => OrderStage::STATUS_IN_PROGRESS,
    ]);
    expect($svc->activeMaterialPrepStage($a->id)?->stage)->toBe('material_prep_sample');

    // Prep already done, order now at cutting → no active prep stage.
    $b = makePrepOrder();
    seedCanonicalStages($b, [
        'payment_verification_sample' => OrderStage::STATUS_COMPLETED,
        'graphic_artwork'             => OrderStage::STATUS_COMPLETED,
        'screen_making'               => OrderStage::STATUS_COMPLETED,
        'material_prep_sample'        => OrderStage::STATUS_COMPLETED,
        'sample_cutting'              => OrderStage::STATUS_IN_PROGRESS,
    ]);
    expect($svc->activeMaterialPrepStage($b->id))->toBeNull();
});
