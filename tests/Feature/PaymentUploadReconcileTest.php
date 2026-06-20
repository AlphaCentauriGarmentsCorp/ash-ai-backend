<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\OrderPaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * PaymentUploadReconcileTest — uploadProof() must reconcile with the gate's
 * auto-created stub (ensureGatePayment) instead of inserting a parallel row,
 * so a single payment never shows twice in the Dashboard "Pending Approvals"
 * queue.
 *
 * Hand-built minimal schema (no RefreshDatabase), mirroring GatePaymentAutoCreateTest.
 */

$TABLES = [
    'csr_activity_logs', 'order_payments', 'orders', 'users',
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

function reconOrder(): Order
{
    return Order::create([
        'po_code' => 'ASH-RECON-' . uniqid(),
        'status'  => 'Pending Approval',
    ]);
}

function reconPayments(): OrderPaymentService
{
    return app(OrderPaymentService::class);
}

function reconStub(Order $order, string $type, float $amount): OrderPayment
{
    // Mirrors what ensureGatePayment writes when the gate opens.
    return OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => $type,
        'amount'       => $amount,
        'status'       => OrderPayment::STATUS_FOR_VERIFICATION,
        'uploaded_at'  => now(),
    ]);
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('updates the gate stub instead of creating a duplicate when a proof is uploaded', function () {
    Storage::fake('public');
    $order = reconOrder();
    $stub  = reconStub($order, OrderPayment::TYPE_SAMPLE, 1000.00);

    reconPayments()->uploadProof($order->id, [
        'payment_type'     => OrderPayment::TYPE_SAMPLE,
        'amount'           => 1000.00,
        'reference_number' => 'GC-12345',
    ], UploadedFile::fake()->create('proof.jpg', 10));

    $payments = OrderPayment::where('order_id', $order->id)->get();

    // The stub was updated in place — still ONE row, now carrying the proof.
    expect($payments)->toHaveCount(1)
        ->and($payments->first()->id)->toBe($stub->id)
        ->and($payments->first()->proof_path)->not->toBeNull()
        ->and($payments->first()->reference_number)->toBe('GC-12345')
        ->and($payments->first()->status)->toBe(OrderPayment::STATUS_FOR_VERIFICATION);
});

it('creates a single row when no stub exists', function () {
    Storage::fake('public');
    $order = reconOrder();

    reconPayments()->uploadProof($order->id, [
        'payment_type' => OrderPayment::TYPE_BALANCE,
        'amount'       => 2354.80,
    ], UploadedFile::fake()->create('proof.jpg', 10));

    expect(OrderPayment::where('order_id', $order->id)->count())->toBe(1);
});

it('never overwrites a verified payment', function () {
    Storage::fake('public');
    $order = reconOrder();

    $verified = OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 1000.00,
        'status'       => OrderPayment::STATUS_VERIFIED,
        'verified_at'  => now(),
    ]);

    reconPayments()->uploadProof($order->id, [
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 1000.00,
    ], UploadedFile::fake()->create('proof.jpg', 10));

    // Verified row is left untouched; the fresh upload becomes its own row.
    expect($verified->fresh()->proof_path)->toBeNull()
        ->and(OrderPayment::where('order_id', $order->id)->count())->toBe(2);
});
