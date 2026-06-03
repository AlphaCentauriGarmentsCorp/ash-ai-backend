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
        // The User model uses SoftDeletes (see migration
        // 2026_05_25_145621_add_soft_deletes_to_users_table). Without this
        // column, any role-resolving User query (e.g. NotificationService
        // resolving recipients) fails with "no such column: users.deleted_at".
        $table->softDeletes();
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

it('exposes the canonical workflow definition with 23 stages', function () {
    $stages = WorkflowStages::all();
    expect($stages)->toHaveCount(23);
    expect(WorkflowStages::keys()[0])->toBe('inquiry');
    expect(WorkflowStages::lastKey())->toBe('client_notification');
    expect(WorkflowStages::maxTier())->toBe(22);

    // Tiers (sequenceOf returns the dependency tier, NOT a 1..N position).
    expect(WorkflowStages::sequenceOf('graphic_artwork'))->toBe(5);
    expect(WorkflowStages::sequenceOf('does_not_exist'))->toBeNull();

    // The sample-phase fork: screen_making ‖ material_prep_sample share tier 6;
    // sample_cutting (the join) is tier 7.
    expect(WorkflowStages::sequenceOf('screen_making'))->toBe(6);
    expect(WorkflowStages::sequenceOf('material_prep_sample'))->toBe(6);
    expect(WorkflowStages::sequenceOf('sample_cutting'))->toBe(7);
    expect(WorkflowStages::isParallelTier(6))->toBeTrue();
    expect(WorkflowStages::isParallelTier(7))->toBeFalse();
    expect(WorkflowStages::stagesAtTier(6))
        ->toContain('screen_making')->toContain('material_prep_sample');

    // The three blocking payment gates (Change 1/20).
    expect(WorkflowStages::paymentGateKeys())->toBe([
        'payment_verification_sample',
        'payment_verification_mass',
        'payment_verification_balance',
    ]);
    expect(WorkflowStages::isPaymentGate('payment_verification_balance'))->toBeTrue();
    expect(WorkflowStages::isPaymentGate('sample_cutting'))->toBeFalse();

    // Phase classification: mass build = 'mass'; gates + material prep = 'preprod'.
    expect(WorkflowStages::phaseFor('mass_cutting'))->toBe('mass');
    expect(WorkflowStages::phaseFor('payment_verification_mass'))->toBe('preprod');
    expect(WorkflowStages::phaseFor('payment_verification_balance'))->toBe('preprod');
    expect(WorkflowStages::phaseFor('material_prep_mass'))->toBe('preprod');
    expect(WorkflowStages::phaseFor('delivery'))->toBe('delivery');

    // Portal-role routing for the now-split mass stages + material prep.
    expect(WorkflowStages::stagesForPortalRole('finance'))
        ->toContain('payment_verification_balance');
    expect(WorkflowStages::stagesForPortalRole('cutter'))
        ->toBe(['sample_cutting', 'mass_cutting']);
    expect(WorkflowStages::stagesForPortalRole('material_prep'))
        ->toBe(['material_prep_sample', 'material_prep_mass']);
});

it('models the sample-phase fork-join via nextActivations', function () {
    // Build a status map with everything pending, then walk the fork.
    $status = [];
    foreach (WorkflowStages::keys() as $k) {
        $status[$k] = OrderStage::STATUS_PENDING;
    }

    // From scratch, only the first stage activates.
    expect(WorkflowStages::nextActivations($status))->toBe(['inquiry']);

    // Complete everything up to and including graphic_artwork.
    foreach (['inquiry', 'quotation', 'quotation_approval',
              'payment_verification_sample', 'graphic_artwork'] as $done) {
        $status[$done] = OrderStage::STATUS_COMPLETED;
    }

    // FORK: graphic done activates BOTH tier-6 branches at once.
    $forked = WorkflowStages::nextActivations($status);
    sort($forked);
    expect($forked)->toBe(['material_prep_sample', 'screen_making']);

    // JOIN guard: finishing only ONE branch must NOT release sample_cutting.
    $status['screen_making'] = OrderStage::STATUS_IN_PROGRESS;
    $status['material_prep_sample'] = OrderStage::STATUS_IN_PROGRESS;
    $status['screen_making'] = OrderStage::STATUS_COMPLETED;
    expect(WorkflowStages::nextActivations($status))->toBe([]);

    // Both branches done → sample_cutting (the join) activates.
    $status['material_prep_sample'] = OrderStage::STATUS_COMPLETED;
    expect(WorkflowStages::nextActivations($status))->toBe(['sample_cutting']);
});

it('prunes legacy non-canonical stages on init', function () {
    $order = makeOrder();

    // Simulate legacy stage rows from the pre-Phase-1 system.
    OrderStage::create([
        'order_id' => $order->id,
        'stage'    => 'graphic_editing',  // legacy slug, not in canonical list
        'sequence' => 0,
        'status'   => OrderStage::STATUS_PENDING,
    ]);
    OrderStage::create([
        'order_id' => $order->id,
        'stage'    => 'sample_creation',  // legacy collapsed stage (now split)
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

    // After init, ONLY the 23 canonical stages remain. (Note: a live data
    // migration RENAMES sample_creation → sample_cutting to preserve history;
    // raw initializeForOrder, with no rename, prunes unknown slugs.)
    $stages = $order->orderStages()->get();
    expect($stages)->toHaveCount(23);

    $keys = $stages->pluck('stage')->all();
    expect($keys)->not->toContain('graphic_editing');
    expect($keys)->not->toContain('sample_creation');
    expect($keys)->not->toContain('production_cutting');

    foreach (WorkflowStages::keys() as $expectedKey) {
        expect($keys)->toContain($expectedKey);
    }
});

it('initializes all 23 workflow stages on order creation', function () {
    $order = makeOrder();

    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    expect($order->orderStages()->count())->toBe(23);

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

    expect($order->orderStages()->count())->toBe(23);
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

    // Try to complete sample_cutting (tier 7) while stage 1 is still in progress
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

it('can complete the entire 23-stage workflow (incl. the parallel fork)', function () {
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

it('advances through the mass-production sequence after the sample fork', function () {
    $order = makeOrder();
    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    $complete = function (string $expected) use ($svc, $order) {
        $current = $svc->getCurrentStage($order->id);
        expect($current)->not->toBeNull();
        expect($current->stage)->toBe($expected);
        $svc->markComplete($current->id);
    };

    // Pre-production + graphic artwork.
    $complete('inquiry');
    $complete('quotation');
    $complete('quotation_approval');
    $complete('payment_verification_sample');
    $complete('graphic_artwork');

    // Parallel fork (tier 6): both branches active at once; getCurrentStage
    // returns the lower-id one first (screen_making), then material_prep_sample.
    $complete('screen_making');
    $complete('material_prep_sample');

    // Join + sample build, then the client sample-approval checkpoint.
    $complete('sample_cutting');
    $complete('sample_printing');
    $complete('sample_sewing');
    $complete('sample_packing');
    $complete('sample_approval');

    // The mass payment gate is now active.
    $current = $svc->getCurrentStage($order->id);
    expect($current->stage)->toBe('payment_verification_mass');
    expect($current->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
    expect($order->fresh()->workflow_status)->toBe('payment_verification_mass');

    // Gate → Material Prep (mass) → Mass Cutting (role-routed mass build).
    $svc->markComplete($current->id);
    expect($svc->getCurrentStage($order->id)->stage)->toBe('material_prep_mass');

    $svc->markComplete($svc->getCurrentStage($order->id)->id);
    expect($svc->getCurrentStage($order->id)->stage)->toBe('mass_cutting');
});

it('backfills stages inserted BEHIND an in-progress stage as completed', function () {
    // Simulate an in-flight order sitting at mass_cutting whose row set is
    // MISSING several stages that sit behind it — i.e. stages added to the
    // workflow after this order had already progressed past that point.
    $order = makeOrder();

    $present = [
        'inquiry'                     => [1,  OrderStage::STATUS_COMPLETED],
        'quotation'                   => [2,  OrderStage::STATUS_COMPLETED],
        'quotation_approval'          => [3,  OrderStage::STATUS_COMPLETED],
        'payment_verification_sample' => [4,  OrderStage::STATUS_COMPLETED],
        'graphic_artwork'             => [5,  OrderStage::STATUS_COMPLETED],
        'screen_making'               => [6,  OrderStage::STATUS_COMPLETED],
        'sample_approval'             => [11, OrderStage::STATUS_COMPLETED],
        'payment_verification_mass'   => [12, OrderStage::STATUS_COMPLETED],
        'mass_cutting'                => [14, OrderStage::STATUS_IN_PROGRESS],
    ];
    foreach ($present as $key => [$seq, $status]) {
        OrderStage::create([
            'order_id'     => $order->id,
            'stage'        => $key,
            'sequence'     => $seq,
            'status'       => $status,
            'started_at'   => $status === OrderStage::STATUS_PENDING ? null : now(),
            'completed_at' => $status === OrderStage::STATUS_COMPLETED ? now() : null,
        ]);
    }

    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    // The full 23-stage set now exists.
    expect($order->orderStages()->count())->toBe(23);

    // Stages that sit BEHIND mass_cutting (tier 14) but were missing must be
    // backfilled COMPLETED — otherwise the integrity guard would stall the
    // order. (material_prep_sample is the new fork sibling at tier 6.)
    foreach ([
        'material_prep_sample', 'sample_cutting', 'sample_printing',
        'sample_sewing', 'sample_packing', 'material_prep_mass',
    ] as $behind) {
        $row = $order->orderStages()->where('stage', $behind)->first();
        expect($row->status)->toBe(OrderStage::STATUS_COMPLETED);
    }

    // mass_cutting is still the active stage, re-sequenced to tier 14.
    $mass = $order->orderStages()->where('stage', 'mass_cutting')->first();
    expect($mass->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
    expect($mass->sequence)->toBe(14);

    // CRITICAL: not stalled — completing mass_cutting advances to mass_printing.
    $next = $svc->markComplete($mass->id);
    expect($next)->not->toBeNull();
    expect($next->stage)->toBe('mass_printing');

    // The backfilled stages carry an honest audit row that explains themselves.
    $bf = $order->orderStages()->where('stage', 'sample_printing')->first();
    $bfAudit = \App\Models\StageAuditLog::where('order_stage_id', $bf->id)
        ->where('action', \App\Models\StageAuditLog::ACTION_COMPLETED)
        ->first();
    expect($bfAudit)->not->toBeNull();
    expect($bfAudit->duration_seconds)->toBeNull();   // no fabricated duration
    expect($bfAudit->notes)->toContain('backfilled');
});

it('resumes at the mass gate when an order finished sample_approval with nothing active', function () {
    // Boundary case: an order that completed through sample_approval (tier 11)
    // but has NOTHING in progress (e.g. it was parked). The mass-phase stages
    // are ahead of the high-water mark, so init must resume the order at the
    // first eligible one — the mass payment gate.
    $order = makeOrder();

    $done = [
        'inquiry' => 1, 'quotation' => 2, 'quotation_approval' => 3,
        'payment_verification_sample' => 4, 'graphic_artwork' => 5,
        'screen_making' => 6, 'sample_approval' => 11,
    ];
    foreach ($done as $key => $seq) {
        OrderStage::create([
            'order_id'     => $order->id,
            'stage'        => $key,
            'sequence'     => $seq,
            'status'       => OrderStage::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
    // Deliberately nothing in_progress.

    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    expect($order->orderStages()->count())->toBe(23);

    // High-water mark is 11 (sample_approval). The mass gate at tier 12 is the
    // first stage ahead of it, so init promotes it to in_progress.
    $payGate = $order->orderStages()->where('stage', 'payment_verification_mass')->first();
    expect($payGate->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
    expect($order->fresh()->workflow_status)->toBe('payment_verification_mass');

    // material_prep_mass (tier 13) remains pending behind it.
    $prep = $order->orderStages()->where('stage', 'material_prep_mass')->first();
    expect($prep->status)->toBe(OrderStage::STATUS_PENDING);
});