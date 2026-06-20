<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderStage;
use App\Models\PaymentMethods;
use App\Services\PendingApprovalsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * DashboardApprovalCardTest — Change Request Auto-Advance §2.3.
 *
 * The Dashboard "Pending Approvals" payment card must let the verifier see who
 * paid (payer), through which channel (method), and WHEN the money was sent
 * (paid_at) — not just when the proof was uploaded. The data layer already
 * captures payer_name / paid_at / payment_method_id (see AwaitingPaymentTest);
 * this pins that PendingApprovalsService::queue() actually SURFACES them on the
 * card row, alongside the reference number, amount and viewable proof.
 *
 * Hand-built minimal schema (no RefreshDatabase), in the AwaitingPaymentTest
 * style, but fuller because queue()->present() exercises the eager loads
 * (order.items, order.currentStage, paymentMethod) and currentGateStage().
 */

$DAC_TABLES = ['order_payments', 'payment_methods', 'order_stages', 'po_items', 'orders', 'users'];

beforeEach(function () use ($DAC_TABLES) {
    foreach ($DAC_TABLES as $t) {
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
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->boolean('rush_order')->default(false);
        $t->unsignedBigInteger('current_stage_id')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
    // Eager-loaded as order.items; only needs to exist (empty is fine).
    Schema::create('po_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedInteger('quantity')->default(0);
        $t->timestamps();
    });
    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('stage');
        $t->unsignedInteger('sequence')->default(0);
        $t->string('status')->default('pending');
        $t->timestamp('started_at')->nullable();
        $t->timestamp('completed_at')->nullable();
        $t->timestamps();
    });
    Schema::create('payment_methods', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('description')->nullable();
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
});

afterEach(function () use ($DAC_TABLES) {
    foreach ($DAC_TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

function dacApprovals(): PendingApprovalsService
{
    return app(PendingApprovalsService::class);
}

it('surfaces payer, method and paid_at on the dashboard payment-approval card', function () {
    Storage::fake('public');

    $gcash = PaymentMethods::create(['name' => 'GCash', 'description' => 'E-wallet']);

    $order = Order::create([
        'po_code'      => 'ASH-DAC-001',
        'client_name'  => 'Acme Tees',
        'client_brand' => 'Acme',
    ]);

    // Order parked at the sample payment gate (in_progress) so the card shows a
    // live gate label and currentGateStage() resolves.
    $gate = OrderStage::create([
        'order_id'   => $order->id,
        'stage'      => 'payment_verification_sample',
        'sequence'   => 4,
        'status'     => OrderStage::STATUS_IN_PROGRESS,
        'started_at' => now(),
    ]);
    $order->update(['current_stage_id' => $gate->id]);

    // A recorded payment awaiting Finance verification, carrying the full detail.
    OrderPayment::create([
        'order_id'          => $order->id,
        'payment_type'      => OrderPayment::TYPE_SAMPLE,
        'amount'            => 1000.00,
        'payment_method_id' => $gcash->id,
        'reference_number'  => 'GC-77777',
        'payer_name'        => 'Juan Dela Cruz',
        'paid_at'           => '2026-06-16 10:00:00',
        'proof_path'        => 'payments/proof.jpg',
        'status'            => OrderPayment::STATUS_FOR_VERIFICATION,
        'uploaded_at'       => now(),
    ]);

    $rows = dacApprovals()->queue();

    expect($rows)->toHaveCount(1);

    $card = $rows[0];

    // The three fields §2.3 was missing.
    expect($card['payer'])->toBe('Juan Dela Cruz')
        ->and($card['method'])->toBe('GCash')
        ->and($card['paid_at'])->not->toBeNull();
    expect(substr((string) $card['paid_at'], 0, 10))->toBe('2026-06-16');

    // Still carries the rest of the card detail the verifier needs.
    expect($card['reference_number'])->toBe('GC-77777')
        ->and($card['amount'])->toBe(1000.0)
        ->and($card['brand'])->toBe('Acme')
        ->and($card['project_no'])->toBe('ASH-DAC-001')
        ->and($card['proof_url'])->not->toBeNull();
});

it('leaves payer/method/paid_at null when none were recorded, without erroring', function () {
    $order = Order::create(['po_code' => 'ASH-DAC-002']);

    OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 600.00,
        'status'       => OrderPayment::STATUS_FOR_VERIFICATION,
        'uploaded_at'  => now(),
    ]);

    $card = dacApprovals()->queue()[0];

    expect($card)->toHaveKeys(['payer', 'method', 'paid_at'])
        ->and($card['payer'])->toBeNull()
        ->and($card['method'])->toBeNull()
        ->and($card['paid_at'])->toBeNull();
});
