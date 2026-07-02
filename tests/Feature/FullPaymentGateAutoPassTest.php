<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderStage;
use App\Services\OrderPaymentService;
use App\Services\OrderStagesService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FullPaymentGateAutoPassTest — payment_plan = 'full_payment' collects the
 * ENTIRE grand total at the sample gate (seq 4, the first workflow stage):
 *
 *   1. the sample-gate stub is typed `full` with amount = grand_total;
 *   2. once the full payment is VERIFIED, the mass (seq 12) and balance
 *      (seq 19) gates auto-pass the moment they become active — the stage
 *      completes with an audit note and a ₱0 VERIFIED OrderPayment is written
 *      as the paper trail;
 *   3. the auto-pass NEVER fires on a plan flag alone: verified payments must
 *      cover the grand total (legacy 60/40 orders degrade gracefully);
 *   4. downpayment-plan orders keep the classic 60/40 gate behavior.
 *
 * Hand-built minimal schema (no RefreshDatabase), mirroring
 * GatePaymentAutoCreateTest, plus orders.payment_plan + orders.grand_total.
 */

$TABLES = [
    'csr_activity_logs', 'order_payments', 'stage_audit_logs', 'notifications',
    'model_has_roles', 'roles', 'order_stages', 'orders', 'users',
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
        $t->json('breakdown_json')->nullable();
        $t->string('payment_plan')->nullable();
        $t->decimal('grand_total', 12, 2)->default(0);
        $t->string('status')->default('Pending Approval');
        $t->string('workflow_status', 32)->default('payment_verification_sample');
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
});

afterEach(function () use ($TABLES) {
    foreach ($TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function fpapOrder(string $plan = 'full_payment', float $grand = 2350.00): Order
{
    return Order::create([
        'po_code'      => 'ASH-FPAP-' . uniqid(),
        'status'       => 'Pending Approval',
        'payment_plan' => $plan,
        'grand_total'  => $grand,
        // full_payment split (as OrderService now stores it); downpayment plan
        // keeps the 60/40 the engine writes.
        'breakdown_json' => $plan === 'full_payment'
            ? ['downpayment' => $grand, 'balance' => 0.0, 'payment_plan' => 'full_payment',
               'sample_breakdown' => ['unit_price' => 1000, 'quantity' => 1]]
            : ['downpayment' => round($grand * 0.60, 2), 'balance' => round($grand * 0.40, 2),
               'sample_breakdown' => ['unit_price' => 1000, 'quantity' => 1]],
    ]);
}

function fpapStages(): OrderStagesService
{
    return app(OrderStagesService::class);
}

/** The order's lowest in_progress stage, or null. */
function fpapActive(Order $order): ?OrderStage
{
    return OrderStage::where('order_id', $order->id)
        ->where('status', OrderStage::STATUS_IN_PROGRESS)
        ->orderBy('sequence')
        ->orderBy('id')
        ->first();
}

/**
 * Complete active stages one by one until the given stage becomes active
 * (or the workflow runs out). Handles the tier-6 parallel fork because it
 * always completes the LOWEST active stage first.
 */
function fpapAdvanceUntil(Order $order, string $targetStage): void
{
    for ($i = 0; $i < 40; $i++) {
        $active = fpapActive($order);
        if (! $active || $active->stage === $targetStage) {
            return;
        }
        fpapStages()->markComplete($active->id);
    }
}

/** Row for a stage slug. */
function fpapStage(Order $order, string $slug): ?OrderStage
{
    return OrderStage::where('order_id', $order->id)->where('stage', $slug)->first();
}

// ---------------------------------------------------------------------
// 1. Sample-gate stub is `full` for the whole grand total
// ---------------------------------------------------------------------

it('types the sample-gate stub as full with amount = grand_total on a full_payment order', function () {
    $order = fpapOrder();
    fpapStages()->initializeForOrder($order);

    $stub = OrderPayment::where('order_id', $order->id)->first();
    expect($stub)->not->toBeNull()
        ->and($stub->payment_type)->toBe(OrderPayment::TYPE_FULL)
        ->and((float) $stub->amount)->toBe(2350.00)
        ->and($stub->status)->toBe(OrderPayment::STATUS_WAITING);
});

it('keeps the sample-gate stub as sample fee on a downpayment order', function () {
    $order = fpapOrder('downpayment');
    fpapStages()->initializeForOrder($order);

    $stub = OrderPayment::where('order_id', $order->id)->first();
    expect($stub->payment_type)->toBe(OrderPayment::TYPE_SAMPLE)
        ->and((float) $stub->amount)->toBe(1000.00);
});

// ---------------------------------------------------------------------
// 2. Mass + balance gates auto-pass once the full payment is verified
// ---------------------------------------------------------------------

it('auto-passes the mass gate with a ₱0 verified paper-trail payment', function () {
    $order = fpapOrder();
    fpapStages()->initializeForOrder($order);

    // Finance verifies the full upfront payment (state simulated directly —
    // the verify() permission path is covered elsewhere).
    OrderPayment::where('order_id', $order->id)->update([
        'status'      => OrderPayment::STATUS_VERIFIED,
        'verified_at' => now(),
    ]);

    // Complete the sample gate → sample sub-flow runs → completing
    // sample_approval (seq 11) promotes the mass gate, which must auto-pass
    // inside that same markComplete call.
    fpapAdvanceUntil($order, 'material_prep_mass');

    $massGate = fpapStage($order, 'payment_verification_mass');
    expect($massGate->status)->toBe(OrderStage::STATUS_COMPLETED)
        ->and($massGate->notes)->toContain('Auto-passed');

    $dp = OrderPayment::where('order_id', $order->id)
        ->where('payment_type', OrderPayment::TYPE_DOWN_PAYMENT)
        ->first();
    expect($dp)->not->toBeNull()
        ->and($dp->status)->toBe(OrderPayment::STATUS_VERIFIED)
        ->and((float) $dp->amount)->toBe(0.00)
        ->and($dp->notes)->toContain('Auto-passed');

    // The workflow moved straight through to Material Prep (seq 13).
    expect(fpapActive($order)->stage)->toBe('material_prep_mass');
});

it('auto-passes the balance gate and reaches delivery', function () {
    $order = fpapOrder();
    fpapStages()->initializeForOrder($order);
    OrderPayment::where('order_id', $order->id)->update([
        'status'      => OrderPayment::STATUS_VERIFIED,
        'verified_at' => now(),
    ]);

    fpapAdvanceUntil($order, 'delivery');

    $balanceGate = fpapStage($order, 'payment_verification_balance');
    expect($balanceGate->status)->toBe(OrderStage::STATUS_COMPLETED)
        ->and($balanceGate->notes)->toContain('Auto-passed');

    $bal = OrderPayment::where('order_id', $order->id)
        ->where('payment_type', OrderPayment::TYPE_BALANCE)
        ->first();
    expect($bal)->not->toBeNull()
        ->and($bal->status)->toBe(OrderPayment::STATUS_VERIFIED)
        ->and((float) $bal->amount)->toBe(0.00);

    expect(fpapActive($order)->stage)->toBe('delivery');
});

// ---------------------------------------------------------------------
// 3. Guard: plan flag alone never skips a gate
// ---------------------------------------------------------------------

it('does NOT auto-pass when verified payments do not cover the grand total', function () {
    $order = fpapOrder();
    fpapStages()->initializeForOrder($order);

    // Only ₱1,000 of the ₱2,350 was verified (legacy / partial situation).
    OrderPayment::where('order_id', $order->id)->update([
        'status'      => OrderPayment::STATUS_VERIFIED,
        'verified_at' => now(),
        'amount'      => 1000.00,
    ]);

    fpapAdvanceUntil($order, 'payment_verification_mass');

    $massGate = fpapStage($order, 'payment_verification_mass');
    expect($massGate->status)->toBe(OrderStage::STATUS_IN_PROGRESS);
});

// ---------------------------------------------------------------------
// 5. Legacy full-plan order that only paid the sample fee (pre-fix orders)
// ---------------------------------------------------------------------

it('bills the remaining amount at the mass gate for a partially-paid full-plan order, then auto-passes the balance gate', function () {
    $order = fpapOrder();
    fpapStages()->initializeForOrder($order);

    // Legacy state: only the ₱1,000 sample fee was collected and verified
    // before the Full-Payment rule shipped.
    OrderPayment::where('order_id', $order->id)->update([
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 1000.00,
        'status'       => OrderPayment::STATUS_VERIFIED,
        'verified_at'  => now(),
    ]);

    // Advance to the mass gate — it must NOT auto-pass (₱1,350 still owed)
    // and its stub must expect exactly the remainder.
    fpapAdvanceUntil($order, 'payment_verification_mass');

    $massGate = fpapStage($order, 'payment_verification_mass');
    expect($massGate->status)->toBe(OrderStage::STATUS_IN_PROGRESS);

    $dp = OrderPayment::where('order_id', $order->id)
        ->where('payment_type', OrderPayment::TYPE_DOWN_PAYMENT)
        ->first();
    expect($dp)->not->toBeNull()
        ->and($dp->status)->toBe(OrderPayment::STATUS_WAITING)
        ->and((float) $dp->amount)->toBe(1350.00);

    // Finance verifies the remainder → the order is now fully paid.
    $dp->update(['status' => OrderPayment::STATUS_VERIFIED, 'verified_at' => now()]);
    fpapStages()->markComplete($massGate->id, 'Payment verified from Dashboard.');

    // Drive through mass production — the balance gate must now auto-pass.
    fpapAdvanceUntil($order, 'delivery');

    $balanceGate = fpapStage($order, 'payment_verification_balance');
    expect($balanceGate->status)->toBe(OrderStage::STATUS_COMPLETED)
        ->and($balanceGate->notes)->toContain('Auto-passed');

    $bal = OrderPayment::where('order_id', $order->id)
        ->where('payment_type', OrderPayment::TYPE_BALANCE)
        ->first();
    expect($bal)->not->toBeNull()
        ->and($bal->status)->toBe(OrderPayment::STATUS_VERIFIED)
        ->and((float) $bal->amount)->toBe(0.00);

    expect(fpapActive($order)->stage)->toBe('delivery');
});

// ---------------------------------------------------------------------
// 6. Downpayment plan keeps the classic 60/40 gates
// ---------------------------------------------------------------------

it('leaves the mass gate blocking on a downpayment order', function () {
    $order = fpapOrder('downpayment');
    fpapStages()->initializeForOrder($order);
    OrderPayment::where('order_id', $order->id)->update([
        'status'      => OrderPayment::STATUS_VERIFIED,
        'verified_at' => now(),
    ]);

    fpapAdvanceUntil($order, 'payment_verification_mass');

    $massGate = fpapStage($order, 'payment_verification_mass');
    expect($massGate->status)->toBe(OrderStage::STATUS_IN_PROGRESS);

    // And its stub is the classic 60% expectation.
    $dp = OrderPayment::where('order_id', $order->id)
        ->where('payment_type', OrderPayment::TYPE_DOWN_PAYMENT)
        ->first();
    expect($dp)->not->toBeNull()
        ->and($dp->status)->toBe(OrderPayment::STATUS_WAITING)
        ->and((float) $dp->amount)->toBe(1410.00);
});
