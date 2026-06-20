<?php

use App\Models\ClientApproval;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Services\OrderStagesService;
use App\Services\SampleApprovalService;
use App\Support\WorkflowStages;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * SampleApprovalTest — Phase 3 sample-approval loop.
 *
 * Run with:
 *     php artisan test --filter=SampleApprovalTest
 *
 * Hand-built minimal schema (no RefreshDatabase), mirroring
 * GatePaymentAutoCreateTest — the approve path calls markComplete +
 * ensureGatePayment + the in-app notification fan-out, so we need the same
 * order_payments / stage_audit_logs / notifications / roles / csr_activity_logs
 * tables, plus client_approvals for the evidence row. The full HTTP layer
 * (portal.csr permission + multipart) is exercised by the curl steps in
 * APPLY-AND-VERIFY.md against the running app.
 */

$TABLES = [
    'client_approvals', 'csr_activity_logs', 'order_payments', 'stage_audit_logs',
    'notifications', 'model_has_roles', 'roles', 'order_stages', 'orders', 'users',
];

beforeEach(function () use ($TABLES) {
    foreach ($TABLES as $t) {
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
    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->json('breakdown_json')->nullable();
        $t->string('status')->default('In Production');
        $t->string('workflow_status', 32)->default('sample_approval');
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
    Schema::create('stage_audit_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('action', 32);
        $t->string('from_status', 32)->nullable();
        $t->string('to_status', 32)->nullable();
        $t->unsignedBigInteger('duration_seconds')->nullable();
        $t->unsignedBigInteger('business_duration_seconds')->nullable();
        $t->text('notes')->nullable();
        $t->timestamp('created_at')->nullable();
    });
    Schema::create('order_payments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('payment_type', 16);
        $t->decimal('amount', 10, 2);
        $t->unsignedBigInteger('payment_method_id')->nullable();
        $t->string('reference_number')->nullable();
        $t->string('payer_name')->nullable();
        $t->timestamp('paid_at')->nullable();
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
    Schema::create('client_approvals', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('kind', 32);
        $t->string('status', 32)->default('waiting');
        $t->timestamp('requested_at')->nullable();
        $t->timestamp('responded_at')->nullable();
        $t->string('screenshot_path', 255)->nullable();
        $t->text('client_response_notes')->nullable();
        $t->text('internal_notes')->nullable();
        $t->unsignedBigInteger('requested_by_user_id')->nullable();
        $t->unsignedBigInteger('recorded_by_user_id')->nullable();
        $t->timestamps();
    });
});

afterEach(function () use ($TABLES) {
    foreach ($TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * Seed an order with the full canonical stage set, positioned AT
 * sample_approval: the sample gate + the whole sample sub-flow completed
 * (seq 4–10), sample_approval (seq 11) in_progress, everything after pending.
 */
function saOrderAtApproval(): Order
{
    $order = Order::create([
        'po_code'         => 'ASH-SA-' . uniqid(),
        'status'          => 'In Production',
        'workflow_status' => 'sample_approval',
        'breakdown_json'  => [
            'downpayment'      => 4842.00,
            'balance'          => 3228.00,
            'sample_breakdown' => ['unit_price' => 1000, 'quantity' => 1],
        ],
    ]);

    foreach (WorkflowStages::all() as $stage) {
        $seq = $stage['seq'];

        $status = match (true) {
            $stage['key'] === 'sample_approval' => OrderStage::STATUS_IN_PROGRESS,
            $seq <= 10                          => OrderStage::STATUS_COMPLETED,
            default                             => OrderStage::STATUS_PENDING,
        };

        OrderStage::create([
            'order_id'      => $order->id,
            'stage'         => $stage['key'],
            'sequence'      => $seq,
            'status'        => $status,
            'started_at'    => $status === OrderStage::STATUS_PENDING ? null : now(),
            'completed_at'  => $status === OrderStage::STATUS_COMPLETED ? now() : null,
            'assigned_role' => $stage['role'] ?? null,
        ]);
    }

    // The sample fee was already paid + verified at the sample gate.
    OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 1000,
        'status'       => OrderPayment::STATUS_VERIFIED,
    ]);

    return $order->fresh();
}

function saService(): SampleApprovalService
{
    return app(SampleApprovalService::class);
}

function saStage(Order $order, string $slug): OrderStage
{
    return OrderStage::where('order_id', $order->id)->where('stage', $slug)->first();
}

// ---------------------------------------------------------------------
// Approve
// ---------------------------------------------------------------------

it('approve completes sample_approval and advances to the mass payment gate', function () {
    $order = saOrderAtApproval();

    $result = saService()->decide($order->id, ClientApproval::STATUS_APPROVED, ['internal_notes' => 'Client signed off']);

    expect($result['outcome'])->toBe('advanced')
        ->and($result['next_stage'])->toBe('payment_verification_mass');

    expect(saStage($order, 'sample_approval')->status)->toBe(OrderStage::STATUS_COMPLETED)
        ->and(saStage($order, 'payment_verification_mass')->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
});

it('approve auto-creates the 60% downpayment stub from the breakdown', function () {
    $order = saOrderAtApproval();

    saService()->decide($order->id, ClientApproval::STATUS_APPROVED, []);

    $dp = OrderPayment::where('order_id', $order->id)
        ->where('payment_type', OrderPayment::TYPE_DOWN_PAYMENT)
        ->first();

    expect($dp)->not->toBeNull()
        ->and((float) $dp->amount)->toBe(4842.00)
        ->and($dp->status)->toBe(OrderPayment::STATUS_WAITING);
});

it('approve records a ClientApproval(sample, approved) evidence row', function () {
    $order = saOrderAtApproval();

    saService()->decide($order->id, ClientApproval::STATUS_APPROVED, ['internal_notes' => 'ok']);

    $ca = ClientApproval::where('order_id', $order->id)->where('kind', 'sample')->first();
    expect($ca)->not->toBeNull()
        ->and($ca->status)->toBe('approved')
        ->and($ca->responded_at)->not->toBeNull();
});

// ---------------------------------------------------------------------
// Reject — loop back to graphic_artwork
// ---------------------------------------------------------------------

it('reject loops the sample sub-flow back to graphic_artwork', function () {
    $order = saOrderAtApproval();

    $result = saService()->decide($order->id, ClientApproval::STATUS_REJECTED, ['client_response_notes' => 'Logo too small']);

    expect($result['outcome'])->toBe('looped_back')
        ->and($result['next_stage'])->toBe('graphic_artwork');

    $ga = saStage($order, 'graphic_artwork');
    expect($ga->status)->toBe(OrderStage::STATUS_IN_PROGRESS)
        ->and($ga->completed_at)->toBeNull();
});

it('reject resets every later sample stage (incl. screens + material prep) to pending', function () {
    $order = saOrderAtApproval();

    saService()->decide($order->id, ClientApproval::STATUS_REJECTED, ['client_response_notes' => 'redo']);

    foreach ([
        'screen_making', 'material_prep_sample', 'sample_cutting',
        'sample_printing', 'sample_sewing', 'sample_packing', 'sample_approval',
    ] as $slug) {
        expect(saStage($order, $slug)->status)->toBe(OrderStage::STATUS_PENDING);
    }
});

it('reject never re-charges the sample fee — the sample gate stays completed', function () {
    $order = saOrderAtApproval();

    saService()->decide($order->id, ClientApproval::STATUS_REJECTED, ['client_response_notes' => 'redo']);

    // The payment-gate stage (seq 4) is sample === false, so it is untouched.
    expect(saStage($order, 'payment_verification_sample')->status)->toBe(OrderStage::STATUS_COMPLETED);

    // Exactly one sample payment exists (the original verified one); none added.
    expect(OrderPayment::where('order_id', $order->id)
        ->where('payment_type', OrderPayment::TYPE_SAMPLE)->count())->toBe(1);
});

it('reject writes a reset audit row and a ClientApproval(sample, rejected)', function () {
    $order = saOrderAtApproval();

    saService()->decide($order->id, ClientApproval::STATUS_REJECTED, ['client_response_notes' => 'fix it']);

    expect(StageAuditLog::where('order_id', $order->id)->where('action', StageAuditLog::ACTION_RESET)->exists())->toBeTrue();

    $ca = ClientApproval::where('order_id', $order->id)->where('kind', 'sample')->first();
    expect($ca->status)->toBe('rejected')
        ->and($ca->client_response_notes)->toBe('fix it');
});

// ---------------------------------------------------------------------
// resetSampleSubflow — direct
// ---------------------------------------------------------------------

it('resetSampleSubflow re-promotes graphic_artwork and leaves the mass phase untouched', function () {
    $order = saOrderAtApproval();

    $promoted = app(OrderStagesService::class)->resetSampleSubflow($order, 'manual');

    expect(collect($promoted)->pluck('stage')->all())->toBe(['graphic_artwork']);

    // Mass-phase stage (seq 12) never reached — still pending, not reset.
    expect(saStage($order, 'payment_verification_mass')->status)->toBe(OrderStage::STATUS_PENDING);
});

// ---------------------------------------------------------------------
// Guards
// ---------------------------------------------------------------------

it('rejects a sample decision when the order is not at sample_approval', function () {
    $order = saOrderAtApproval();
    saStage($order, 'sample_approval')->update(['status' => OrderStage::STATUS_PENDING]);

    saService()->decide($order->id, ClientApproval::STATUS_APPROVED, []);
})->throws(ValidationException::class);

it('requires a reason when rejecting a sample', function () {
    $order = saOrderAtApproval();

    saService()->decide($order->id, ClientApproval::STATUS_REJECTED, []);
})->throws(ValidationException::class);

it('rejects an unknown decision value', function () {
    $order = saOrderAtApproval();

    saService()->decide($order->id, 'maybe', []);
})->throws(ValidationException::class);
