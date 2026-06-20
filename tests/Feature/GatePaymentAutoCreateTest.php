<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderStage;
use App\Services\OrderPaymentService;
use App\Services\OrderStagesService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GatePaymentAutoCreateTest — when an order reaches a Payment Verification gate
 * (sample / mass / balance), a pending OrderPayment is auto-created in
 * `waiting` so it surfaces on the CSR awaiting-payment list. It becomes
 * `for_verification` (Finance's Dashboard queue) only once CSR records the
 * actual payment with proof. The expected amount is read from breakdown_json.
 *
 * Hand-built minimal schema (no RefreshDatabase), mirroring WorkflowEngineTest,
 * plus order_payments + csr_activity_logs and the orders.breakdown_json column.
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

function gpaBreakdown(): array
{
    return [
        'downpayment'      => 4842.00,
        'balance'          => 3228.00,
        'sample_breakdown' => ['unit_price' => 1000, 'quantity' => 1],
    ];
}

function gpaOrder(): Order
{
    return Order::create([
        'po_code'        => 'ASH-GPA-' . uniqid(),
        'status'         => 'Pending Approval',
        'breakdown_json' => gpaBreakdown(),
    ]);
}

function gpaSeedGate(Order $order, string $stage, int $seq): OrderStage
{
    return OrderStage::create([
        'order_id'   => $order->id,
        'stage'      => $stage,
        'sequence'   => $seq,
        'status'     => OrderStage::STATUS_IN_PROGRESS,
        'started_at' => now(),
    ]);
}

function gpaPayments(): OrderPaymentService
{
    return app(OrderPaymentService::class);
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('auto-creates a waiting sample payment when an order initializes at the sample gate', function () {
    $order = gpaOrder();
    app(OrderStagesService::class)->initializeForOrder($order);

    $first = $order->orderStages()->orderBy('sequence')->first();
    expect($first->stage)->toBe('payment_verification_sample')
        ->and($first->status)->toBe(OrderStage::STATUS_IN_PROGRESS);

    $payments = OrderPayment::where('order_id', $order->id)->get();
    expect($payments)->toHaveCount(1);

    $p = $payments->first();
    expect($p->payment_type)->toBe(OrderPayment::TYPE_SAMPLE)
        ->and($p->status)->toBe(OrderPayment::STATUS_WAITING)
        ->and((float) $p->amount)->toBe(1000.00)
        ->and($p->uploaded_by_user_id)->toBeNull()
        ->and($p->proof_path)->toBeNull();
});

it('is idempotent — re-initialization never duplicates the gate payment', function () {
    $order = gpaOrder();
    $svc = app(OrderStagesService::class);
    $svc->initializeForOrder($order);
    $svc->initializeForOrder($order);
    gpaPayments()->ensureGatePayment($order->fresh());

    expect(OrderPayment::where('order_id', $order->id)->count())->toBe(1);
});

it('creates a down_payment from breakdown.downpayment at the mass gate', function () {
    $order = gpaOrder();
    gpaSeedGate($order, 'payment_verification_mass', 12);

    $p = gpaPayments()->ensureGatePayment($order);
    expect($p)->not->toBeNull()
        ->and($p->payment_type)->toBe(OrderPayment::TYPE_DOWN_PAYMENT)
        ->and((float) $p->amount)->toBe(4842.00)
        ->and($p->status)->toBe(OrderPayment::STATUS_WAITING);
});

it('creates a balance payment from breakdown.balance at the balance gate', function () {
    $order = gpaOrder();
    gpaSeedGate($order, 'payment_verification_balance', 19);

    $p = gpaPayments()->ensureGatePayment($order);
    expect($p->payment_type)->toBe(OrderPayment::TYPE_BALANCE)
        ->and((float) $p->amount)->toBe(3228.00);
});

it('does nothing when the active stage is not a payment gate', function () {
    $order = gpaOrder();
    gpaSeedGate($order, 'graphic_artwork', 5);

    expect(gpaPayments()->ensureGatePayment($order))->toBeNull();
    expect(OrderPayment::where('order_id', $order->id)->count())->toBe(0);
});

it('never resurrects a payment that already exists for the gate type (incl. rejected)', function () {
    $order = gpaOrder();
    gpaSeedGate($order, 'payment_verification_sample', 4);

    OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 1000,
        'status'       => OrderPayment::STATUS_REJECTED,
    ]);

    $p = gpaPayments()->ensureGatePayment($order);
    expect($p->status)->toBe(OrderPayment::STATUS_REJECTED)        // returns the existing row
        ->and(OrderPayment::where('order_id', $order->id)->count())->toBe(1); // no new row
});
