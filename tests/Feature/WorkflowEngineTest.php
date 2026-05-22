<?php

/**
 * Phase 1 – Sequential Workflow Engine tests.
 *
 * Run with:
 *     php artisan test --filter=WorkflowEngineTest
 *
 * IMPORTANT: These tests do NOT use RefreshDatabase – they build a minimal
 * isolated schema (orders + order_stages tables only) on the in-memory SQLite
 * database. This keeps them independent from any other migration or seeder
 * in the project, including any that might use MySQL-only SQL. Each test
 * runs against a fresh schema.
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Services\OrderStagesService;
use App\Support\WorkflowStages;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------
// Schema bootstrap – runs before each test
// ---------------------------------------------------------------------
beforeEach(function () {
    // Build only the tables our tests touch. This is the minimum subset
    // required for OrderStagesService to function (which now also depends
    // on NotificationService — that calls into Spatie's role lookup, so
    // we add the minimum schema for that as well).
    foreach ([
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
    });

    // Spatie Permission – minimum tables for User::role(...) to work.
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

        // The new Phase 1 columns:
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

    // Phase 4 — stage transition audit log. OrderStagesService writes a
    // row here on every transition (the 'started' write in
    // initializeForOrder, plus completed/delayed/etc. in markComplete and
    // friends), so this minimal schema MUST include it or every test that
    // touches the service throws "no such table: stage_audit_logs".
    // Columns mirror database/migrations/*_create_stage_audit_logs_table.
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

    // Pre-seed every role NotificationService may query during stage
    // transitions. Spatie's User::role([...]) throws if any role in the
    // list doesn't exist – seeding empties keeps the lookup valid.
    $roles = [
        'superadmin', 'admin', 'general_manager',
        'csr', 'finance', 'purchasing', 'warehouse_manager',
        'graphic_artist', 'screen_maker', 'sample_maker',
        'cutter', 'printer', 'sewer', 'quality_assurance',
        'packer', 'driver', 'logistics',
    ];
    foreach ($roles as $role) {
        \Illuminate\Support\Facades\DB::table('roles')->insert([
            'name'       => $role,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Flush Spatie's permission cache so freshly-inserted roles are
    // picked up immediately during the test run.
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
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

/**
 * Creates a minimum-viable Order without going through the form-request
 * validators. Skips file uploads and PoItem creation – we only care
 * about the stage workflow.
 */
function makeOrder(): Order
{
    return Order::create([
        'po_code'              => 'ASH-TEST-' . uniqid(),
        'client_brand'         => 'TestBrand',
        'deadline'             => now()->addDays(30),
        'priority'             => 'normal',
        'brand'                => 'sorbetes',
        'courier'              => 'lalamove',
        'method'               => 'standard',
        'receiver_name'        => 'Test',
        'receiver_contact'     => '0000',
        'address'              => 'Test',
        'design_name'          => 'Test',
        'apparel_type'         => 'tshirt',
        'pattern_type'         => 'tshirt',
        'service_type'         => 'full',
        'print_method'         => 'silkscreen',
        'print_service'        => 'standard',
        'size_label'           => 'standard',
        'print_label_placement' => 'inside_collar',
        'fabric_type'          => 'cotton',
        'fabric_supplier'      => 'TestSupplier',
        'fabric_color'         => 'black',
        'thread_color'         => 'black',
        'ribbing_color'        => 'black',
        'total_price'          => 1000,
        'average_unit_price'   => 100,
        'total_quantity'       => 10,
        'deposit'              => 50,
    ]);
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('exposes the canonical workflow definition with 16 stages', function () {
    $stages = WorkflowStages::all();
    expect($stages)->toHaveCount(16);
    expect(WorkflowStages::keys()[0])->toBe('inquiry');

    $keys = WorkflowStages::keys();
    expect($keys[count($keys) - 1])->toBe('client_notification');

    // sequenceOf works (graphic_artwork is BEFORE the new gates, unchanged)
    expect(WorkflowStages::sequenceOf('graphic_artwork'))->toBe(5);
    expect(WorkflowStages::sequenceOf('does_not_exist'))->toBeNull();

    // The two new §5 mass-gate checkpoints sit between sample_approval (8)
    // and mass_production (now 11), in the correct order.
    expect(WorkflowStages::sequenceOf('sample_approval'))->toBe(8);
    expect(WorkflowStages::sequenceOf('payment_verification_mass'))->toBe(9);
    expect(WorkflowStages::sequenceOf('purchase_materials'))->toBe(10);
    expect(WorkflowStages::sequenceOf('mass_production'))->toBe(11);

    // nextAfter chains across the new gates correctly
    expect(WorkflowStages::nextAfter('inquiry')['key'])->toBe('quotation');
    expect(WorkflowStages::nextAfter('sample_approval')['key'])->toBe('payment_verification_mass');
    expect(WorkflowStages::nextAfter('payment_verification_mass')['key'])->toBe('purchase_materials');
    expect(WorkflowStages::nextAfter('purchase_materials')['key'])->toBe('mass_production');
    expect(WorkflowStages::nextAfter('client_notification'))->toBeNull();

    // The new gates are administrative checkpoints, not production-cycle
    // work — they must classify as 'preprod' so mass cycle-time stays clean.
    expect(WorkflowStages::phaseFor('payment_verification_mass'))->toBe('preprod');
    expect(WorkflowStages::phaseFor('purchase_materials'))->toBe('preprod');
    expect(WorkflowStages::phaseFor('mass_production'))->toBe('mass');

    // The new gates resolve to their responsible portal roles.
    expect(WorkflowStages::stagesForPortalRole('finance'))
        ->toContain('payment_verification_mass');
    expect(WorkflowStages::stagesForPortalRole('purchasing'))
        ->toContain('purchase_materials');
});

it('prunes legacy non-canonical stages on init', function () {
    $order = makeOrder();

    // Simulate legacy stage rows from the pre-Phase-1 system.
    OrderStage::create([
        'order_id' => $order->id,
        'stage'    => 'graphic_editing',  // legacy slug, not in canonical 16
        'sequence' => 0,
        'status'   => OrderStage::STATUS_PENDING,
    ]);
    OrderStage::create([
        'order_id' => $order->id,
        'stage'    => 'sample_cutting',  // legacy slug
        'sequence' => 0,
        'status'   => OrderStage::STATUS_PENDING,
    ]);
    OrderStage::create([
        'order_id' => $order->id,
        'stage'    => 'production_cutting',  // legacy slug
        'sequence' => 0,
        'status'   => OrderStage::STATUS_PENDING,
    ]);

    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    // After init, ONLY the 16 canonical stages remain.
    $stages = $order->orderStages()->get();
    expect($stages)->toHaveCount(16);

    $keys = $stages->pluck('stage')->all();
    expect($keys)->not->toContain('graphic_editing');
    expect($keys)->not->toContain('sample_cutting');
    expect($keys)->not->toContain('production_cutting');

    foreach (WorkflowStages::keys() as $expectedKey) {
        expect($keys)->toContain($expectedKey);
    }
});

it('initializes all 16 workflow stages on order creation', function () {
    $order = makeOrder();

    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    expect($order->orderStages()->count())->toBe(16);

    // First stage must be in_progress
    $first = $order->orderStages()->orderBy('sequence')->first();
    expect($first->stage)->toBe('inquiry')
        ->and($first->status)->toBe(OrderStage::STATUS_IN_PROGRESS)
        ->and($first->sequence)->toBe(1)
        ->and($first->started_at)->not->toBeNull();

    // All other stages must be pending
    $others = $order->orderStages()->where('sequence', '>', 1)->get();
    foreach ($others as $s) {
        expect($s->status)->toBe(OrderStage::STATUS_PENDING);
        expect($s->started_at)->toBeNull();
    }

    // Order workflow_status must mirror the active stage
    expect($order->fresh()->workflow_status)->toBe('inquiry');
});

it('is idempotent on re-initialization', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);

    $svc->initializeForOrder($order);
    $svc->initializeForOrder($order); // safe to call twice

    expect($order->orderStages()->count())->toBe(16);
});

it('advances stage and auto-promotes next', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    $first = $order->orderStages()->orderBy('sequence')->first();
    $next = $svc->markComplete($first->id, 'all good');

    // First should be completed
    expect($first->fresh()->status)->toBe(OrderStage::STATUS_COMPLETED);
    expect($first->fresh()->completed_at)->not->toBeNull();
    expect($first->fresh()->notes)->toBe('all good');

    // Returned next is in_progress
    expect($next)->not->toBeNull();
    expect($next->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
    expect($next->sequence)->toBe(2);
    expect($next->stage)->toBe('quotation');

    // Order cache updated
    expect($order->fresh()->workflow_status)->toBe('quotation');
});

it('blocks completing a stage out of sequence', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    // Try to complete sample_creation (sequence 7) while stage 1 is in progress
    $stage7 = $order->orderStages()->where('sequence', 7)->first();

    // Stage 7 is currently 'pending' – not in_progress – so ValidationException
    expect(fn () => $svc->markComplete($stage7->id))
        ->toThrow(ValidationException::class);
});

it('blocks completing an already-completed stage', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    $first = $order->orderStages()->orderBy('sequence')->first();
    $svc->markComplete($first->id);

    expect(fn () => $svc->markComplete($first->id))
        ->toThrow(ValidationException::class);
});

it('flags a stage as delayed and mirrors to order', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    $first = $order->orderStages()->orderBy('sequence')->first();
    $svc->markDelayed($first->id, 'waiting on client artwork');

    $first->refresh();
    expect($first->status)->toBe(OrderStage::STATUS_DELAYED);
    expect($first->delayed_at)->not->toBeNull();
    expect($first->notes)->toBe('waiting on client artwork');

    expect($order->fresh()->delayed_at)->not->toBeNull();
});

it('allows completing a delayed stage', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    $first = $order->orderStages()->orderBy('sequence')->first();
    $svc->markDelayed($first->id, 'paused');
    $next = $svc->markComplete($first->id, 'unblocked');

    expect($first->fresh()->status)->toBe(OrderStage::STATUS_COMPLETED);
    expect($next->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
});

it('puts a stage on hold and resumes it', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    $first = $order->orderStages()->orderBy('sequence')->first();
    $svc->markOnHold($first->id, 'finance review');
    expect($first->fresh()->status)->toBe(OrderStage::STATUS_ON_HOLD);

    $svc->resume($first->id);
    expect($first->fresh()->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
});

it('moves stage to for_approval and back to completed', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    $first = $order->orderStages()->orderBy('sequence')->first();
    $svc->markForApproval($first->id, 'submitted');
    expect($first->fresh()->status)->toBe(OrderStage::STATUS_FOR_APPROVAL);

    $svc->markComplete($first->id, 'approved');
    expect($first->fresh()->status)->toBe(OrderStage::STATUS_COMPLETED);
});

it('can complete the entire 16-stage workflow', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    foreach (WorkflowStages::keys() as $key) {
        $current = $svc->getCurrentStage($order->id);
        expect($current)->not->toBeNull();
        expect($current->stage)->toBe($key);

        $svc->markComplete($current->id);
    }

    // All done
    expect($order->orderStages()->where('status', '!=', OrderStage::STATUS_COMPLETED)->count())->toBe(0);
    expect($order->fresh()->workflow_status)->toBe('order_completed');
});

// ---------------------------------------------------------------------
// Workstream A — the two new §5 mass-gate checkpoints
// ---------------------------------------------------------------------

it('advances cleanly through the new mass-gate checkpoints in sequence', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    // Walk up to sample_approval (sequence 8) and complete it.
    foreach (['inquiry', 'quotation', 'quotation_approval',
              'payment_verification_sample', 'graphic_artwork',
              'screen_making', 'sample_creation', 'sample_approval'] as $key) {
        $current = $svc->getCurrentStage($order->id);
        expect($current->stage)->toBe($key);
        $svc->markComplete($current->id);
    }

    // The next active stage must now be the mass payment gate.
    $current = $svc->getCurrentStage($order->id);
    expect($current->stage)->toBe('payment_verification_mass');
    expect($current->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
    expect($order->fresh()->workflow_status)->toBe('payment_verification_mass');

    // Finance completes it → purchase_materials becomes active.
    $svc->markComplete($current->id);
    $current = $svc->getCurrentStage($order->id);
    expect($current->stage)->toBe('purchase_materials');
    expect($current->status)->toBe(OrderStage::STATUS_IN_PROGRESS);

    // Purchasing completes it → mass_production becomes active.
    $svc->markComplete($current->id);
    $current = $svc->getCurrentStage($order->id);
    expect($current->stage)->toBe('mass_production');
    expect($current->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
});

it('backfills gates inserted BEHIND an in-progress stage as completed', function () {
    // Simulate a legacy/in-flight order that predates the two new gates:
    // it has the OLD 14-stage shape and is sitting at mass_production.
    $order = makeOrder();

    $legacyKeys = [
        'inquiry', 'quotation', 'quotation_approval',
        'payment_verification_sample', 'graphic_artwork', 'screen_making',
        'sample_creation', 'sample_approval',
        // (no payment_verification_mass / purchase_materials — they didn't exist)
        'mass_production', 'quality_control', 'packing',
        'delivery', 'order_completed', 'client_notification',
    ];
    foreach ($legacyKeys as $i => $key) {
        // Everything up to sample_approval completed; mass_production active;
        // the rest pending — a realistic in-flight order.
        $status = match (true) {
            $key === 'mass_production'         => OrderStage::STATUS_IN_PROGRESS,
            $i < array_search('mass_production', $legacyKeys, true) => OrderStage::STATUS_COMPLETED,
            default                            => OrderStage::STATUS_PENDING,
        };
        OrderStage::create([
            'order_id'     => $order->id,
            'stage'        => $key,
            'sequence'     => $i + 1,        // legacy sequence
            'status'       => $status,
            'started_at'   => $status === OrderStage::STATUS_PENDING ? null : now(),
            'completed_at' => $status === OrderStage::STATUS_COMPLETED ? now() : null,
        ]);
    }

    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    // Now there should be 16 stages, correctly sequenced.
    expect($order->orderStages()->count())->toBe(16);

    // The two inserted gates sit BEHIND mass_production, so the backfill
    // guard must have marked them completed (NOT pending) — otherwise the
    // order would be permanently stalled by the integrity guard.
    $payGate = $order->orderStages()->where('stage', 'payment_verification_mass')->first();
    $buyGate = $order->orderStages()->where('stage', 'purchase_materials')->first();
    expect($payGate->status)->toBe(OrderStage::STATUS_COMPLETED);
    expect($buyGate->status)->toBe(OrderStage::STATUS_COMPLETED);
    expect($payGate->sequence)->toBe(9);
    expect($buyGate->sequence)->toBe(10);

    // mass_production is still the active stage and was re-sequenced to 11.
    $mass = $order->orderStages()->where('stage', 'mass_production')->first();
    expect($mass->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
    expect($mass->sequence)->toBe(11);

    // CRITICAL: the order is NOT stalled — mass_production can still be
    // completed because no earlier stage is unfinished.
    $next = $svc->markComplete($mass->id);
    expect($next)->not->toBeNull();
    expect($next->stage)->toBe('quality_control');

    // The backfilled gates have an honest audit row explaining themselves.
    $bfAudit = \App\Models\StageAuditLog::where('order_stage_id', $payGate->id)
        ->where('action', \App\Models\StageAuditLog::ACTION_COMPLETED)
        ->first();
    expect($bfAudit)->not->toBeNull();
    expect($bfAudit->duration_seconds)->toBeNull();   // no fabricated duration
    expect($bfAudit->notes)->toContain('backfilled');
});

it('resumes at the first new gate when an order finished sample_approval with nothing active', function () {
    // Boundary case: an order that completed through sample_approval (8)
    // but has NOTHING in progress (e.g. it was waiting). The new gates are
    // inserted AHEAD of the high-water mark (8), so they must be PENDING,
    // and the order must resume at the first of them.
    $order = makeOrder();

    foreach (['inquiry', 'quotation', 'quotation_approval',
              'payment_verification_sample', 'graphic_artwork',
              'screen_making', 'sample_creation', 'sample_approval'] as $i => $key) {
        OrderStage::create([
            'order_id'     => $order->id,
            'stage'        => $key,
            'sequence'     => $i + 1,
            'status'       => OrderStage::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
    // Note: deliberately NO mass_production+ rows and nothing in_progress.

    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    expect($order->orderStages()->count())->toBe(16);

    // High-water mark was 8 (sample_approval). The new gates at 9 and 10 are
    // AHEAD of it, so they are pending — and step-6 promotion starts the
    // first pending stage = payment_verification_mass.
    $payGate = $order->orderStages()->where('stage', 'payment_verification_mass')->first();
    expect($payGate->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
    expect($order->fresh()->workflow_status)->toBe('payment_verification_mass');

    // purchase_materials remains pending behind it.
    $buyGate = $order->orderStages()->where('stage', 'purchase_materials')->first();
    expect($buyGate->status)->toBe(OrderStage::STATUS_PENDING);
});