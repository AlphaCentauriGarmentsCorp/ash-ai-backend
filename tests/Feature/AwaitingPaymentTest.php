<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\OrderPaymentService;
use App\Services\PendingApprovalsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * AwaitingPaymentTest — Phase 2, step 2a.
 *
 * Two guarantees for the CSR awaiting-payment list:
 *   1. awaitingCount() counts only the "still to record" rows (waiting +
 *      rejected) and never the for_verification / verified ones — so the CSR
 *      list and the Finance verify queue stay disjoint.
 *   2. uploadProof() records payer_name + paid_at, reconciles onto the existing
 *      waiting stub (no duplicate row), and flips it to for_verification — i.e.
 *      it moves off the awaiting list and onto the Dashboard.
 *
 * Hand-built minimal schema (no RefreshDatabase), mirroring
 * PaymentUploadReconcileTest, plus the new payer_name / paid_at columns.
 */

$TABLES = ['csr_activity_logs', 'order_payments', 'orders', 'users'];

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

function awpOrder(): Order
{
    return Order::create([
        'po_code' => 'ASH-AWP-' . uniqid(),
        'status'  => 'Pending Approval',
    ]);
}

function awpPayments(): OrderPaymentService
{
    return app(OrderPaymentService::class);
}

function awpApprovals(): PendingApprovalsService
{
    return app(PendingApprovalsService::class);
}

function awpPayment(Order $order, string $type, string $status): OrderPayment
{
    return OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => $type,
        'amount'       => 1000.00,
        'status'       => $status,
        'uploaded_at'  => now(),
    ]);
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('counts only waiting and rejected rows as awaiting, never for_verification or verified', function () {
    $order = awpOrder();

    awpPayment($order, OrderPayment::TYPE_SAMPLE,       OrderPayment::STATUS_WAITING);
    awpPayment($order, OrderPayment::TYPE_DOWN_PAYMENT, OrderPayment::STATUS_REJECTED);
    awpPayment($order, OrderPayment::TYPE_BALANCE,      OrderPayment::STATUS_FOR_VERIFICATION);
    awpPayment($order, OrderPayment::TYPE_FULL,         OrderPayment::STATUS_VERIFIED);

    // waiting + rejected = 2; for_verification + verified are excluded.
    expect(awpApprovals()->awaitingCount())->toBe(2);
});

it('records payer_name and paid_at, reconciles the waiting stub, and flips it to for_verification', function () {
    Storage::fake('public');
    $order = awpOrder();

    // Gate stub as ensureGatePayment now writes it: waiting, no proof.
    $stub = awpPayment($order, OrderPayment::TYPE_SAMPLE, OrderPayment::STATUS_WAITING);
    expect(awpApprovals()->awaitingCount())->toBe(1);

    awpPayments()->uploadProof($order->id, [
        'payment_type'     => OrderPayment::TYPE_SAMPLE,
        'amount'           => 1000.00,
        'reference_number' => 'GC-55555',
        'payer_name'       => 'Juan Dela Cruz',
        'paid_at'          => '2026-06-16 10:00:00',
    ], UploadedFile::fake()->create('proof.jpg', 10));

    $rows = OrderPayment::where('order_id', $order->id)->get();

    // One row (reconciled onto the stub), now carrying payer/date/proof, and
    // promoted to for_verification — so it leaves the awaiting list.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($stub->id)
        ->and($rows->first()->payer_name)->toBe('Juan Dela Cruz')
        ->and($rows->first()->paid_at?->format('Y-m-d H:i'))->toBe('2026-06-16 10:00')
        ->and($rows->first()->status)->toBe(OrderPayment::STATUS_FOR_VERIFICATION)
        ->and($rows->first()->proof_path)->not->toBeNull();

    expect(awpApprovals()->awaitingCount())->toBe(0);
});
