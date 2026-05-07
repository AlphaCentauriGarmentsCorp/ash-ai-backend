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
    // required for OrderStagesService to function.
    Schema::dropIfExists('order_stages');
    Schema::dropIfExists('orders');

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
});

afterEach(function () {
    Schema::dropIfExists('order_stages');
    Schema::dropIfExists('orders');
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

it('exposes the canonical workflow definition with 14 stages', function () {
    $stages = WorkflowStages::all();
    expect($stages)->toHaveCount(14);
    expect(WorkflowStages::keys()[0])->toBe('inquiry');

    $keys = WorkflowStages::keys();
    expect($keys[count($keys) - 1])->toBe('client_notification');

    // sequenceOf works
    expect(WorkflowStages::sequenceOf('graphic_artwork'))->toBe(5);
    expect(WorkflowStages::sequenceOf('does_not_exist'))->toBeNull();

    // nextAfter works
    expect(WorkflowStages::nextAfter('inquiry')['key'])->toBe('quotation');
    expect(WorkflowStages::nextAfter('client_notification'))->toBeNull();
});

it('prunes legacy non-canonical stages on init', function () {
    $order = makeOrder();

    // Simulate legacy stage rows from the pre-Phase-1 system.
    OrderStage::create([
        'order_id' => $order->id,
        'stage'    => 'graphic_editing',  // legacy slug, not in canonical 14
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

    // After init, ONLY the 14 canonical stages remain.
    $stages = $order->orderStages()->get();
    expect($stages)->toHaveCount(14);

    $keys = $stages->pluck('stage')->all();
    expect($keys)->not->toContain('graphic_editing');
    expect($keys)->not->toContain('sample_cutting');
    expect($keys)->not->toContain('production_cutting');

    foreach (WorkflowStages::keys() as $expectedKey) {
        expect($keys)->toContain($expectedKey);
    }
});

it('initializes all 14 workflow stages on order creation', function () {
    $order = makeOrder();

    /** @var OrderStagesService $svc */
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);

    expect($order->orderStages()->count())->toBe(14);

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

    expect($order->orderStages()->count())->toBe(14);
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

it('can complete the entire 14-stage workflow', function () {
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
