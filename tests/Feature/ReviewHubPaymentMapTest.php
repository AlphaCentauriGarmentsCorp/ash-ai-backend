<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderStage;
use App\Models\PaymentMethods;
use App\Models\User;
use App\Services\OrderPaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ReviewHubPaymentMapTest — OrderPaymentService::forReviewHub() keys the
 * order's payment-gate stages (by order_stage_id) to their FULL payment
 * record so the Review Hub keeps showing a verified payment after the
 * Dashboard "Pending Approvals" queue drops it.
 *
 * Covered:
 *   1. verified sample payment maps to the sample gate with method /
 *      verifier names resolved;
 *   2. legacy full-plan order whose payment was typed `sample` pre-fix
 *      still resolves on the sample gate (candidate fallback);
 *   3. mass gate maps its down_payment; gates with no payment are absent.
 *
 * Hand-built minimal schema, mirroring GatePaymentAutoCreateTest, plus
 * payment_methods and orders.payment_plan/grand_total.
 */

$TABLES = [
    'csr_activity_logs', 'order_payments', 'payment_methods',
    'stage_audit_logs', 'notifications',
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
    Schema::create('payment_methods', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->text('description')->nullable();
        $t->timestamps();
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
});

afterEach(function () use ($TABLES) {
    foreach ($TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function rhpmOrder(string $plan = 'downpayment'): Order
{
    return Order::create([
        'po_code'        => 'ASH-RHPM-' . uniqid(),
        'status'         => 'Pending Approval',
        'payment_plan'   => $plan,
        'grand_total'    => 2350.00,
        'breakdown_json' => ['downpayment' => 1410.00, 'balance' => 940.00,
            'sample_breakdown' => ['unit_price' => 1000, 'quantity' => 1]],
    ]);
}

function rhpmGate(Order $order, string $stage, int $seq, string $status = 'completed'): OrderStage
{
    return OrderStage::create([
        'order_id' => $order->id,
        'stage'    => $stage,
        'sequence' => $seq,
        'status'   => $status,
    ]);
}

function rhpmSvc(): OrderPaymentService
{
    return app(OrderPaymentService::class);
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('maps a verified sample payment onto the sample gate with names resolved', function () {
    $order = rhpmOrder();
    $gate  = rhpmGate($order, 'payment_verification_sample', 4);
    rhpmGate($order, 'graphic_artwork', 5, 'in_progress');

    $finance = User::create(['name' => 'Fin Ance', 'email' => 'fin@x.test']);
    $csr     = User::create(['name' => 'C. S. Rep', 'email' => 'csr@x.test']);
    $gcash   = PaymentMethods::create(['name' => 'GCash', 'description' => 'GCash E-Wallet']);

    OrderPayment::create([
        'order_id'            => $order->id,
        'payment_type'        => OrderPayment::TYPE_SAMPLE,
        'amount'              => 1000.00,
        'payment_method_id'   => $gcash->id,
        'reference_number'    => 'REF-123',
        'payer_name'          => 'Mark',
        'paid_at'             => now(),
        'status'              => OrderPayment::STATUS_VERIFIED,
        'uploaded_by_user_id' => $csr->id,
        'uploaded_at'         => now(),
        'verified_by_user_id' => $finance->id,
        'verified_at'         => now(),
    ]);

    $map = rhpmSvc()->forReviewHub($order);

    expect($map)->toHaveKey($gate->id);
    $row = $map[$gate->id];
    expect($row['payment_type'])->toBe('sample')
        ->and($row['amount'])->toBe(1000.00)
        ->and($row['status'])->toBe('verified')
        ->and($row['payer_name'])->toBe('Mark')
        ->and($row['method_name'])->toBe('GCash')
        ->and($row['reference_number'])->toBe('REF-123')
        ->and($row['uploaded_by_name'])->toBe('C. S. Rep')
        ->and($row['verified_by_name'])->toBe('Fin Ance')
        ->and($row['verified_at'])->not->toBeNull();
});

it('resolves a legacy sample-typed payment on a full_payment order (candidate fallback)', function () {
    $order = rhpmOrder('full_payment');
    $gate  = rhpmGate($order, 'payment_verification_sample', 4);

    OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE, // recorded pre-fix
        'amount'       => 1000.00,
        'status'       => OrderPayment::STATUS_VERIFIED,
        'verified_at'  => now(),
    ]);

    $map = rhpmSvc()->forReviewHub($order);

    expect($map)->toHaveKey($gate->id)
        ->and($map[$gate->id]['payment_type'])->toBe('sample');
});

it('maps the mass-gate down_payment and omits gates without a payment', function () {
    $order = rhpmOrder();
    rhpmGate($order, 'payment_verification_sample', 4);
    $mass    = rhpmGate($order, 'payment_verification_mass', 12, 'in_progress');
    $balance = rhpmGate($order, 'payment_verification_balance', 19, 'pending');

    OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_DOWN_PAYMENT,
        'amount'       => 1410.00,
        'status'       => OrderPayment::STATUS_WAITING,
        'uploaded_at'  => now(),
    ]);

    $map = rhpmSvc()->forReviewHub($order);

    expect($map)->toHaveKey($mass->id)
        ->and($map[$mass->id]['payment_type'])->toBe('down_payment')
        ->and($map[$mass->id]['amount'])->toBe(1410.00)
        ->and($map)->not->toHaveKey($balance->id);
});
