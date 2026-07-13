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

it('labels each queued payment by its own type, not the order gate (RC-6)', function () {
    // Reproduces seed order ASH-2026-000019 (order 30): a sample fee AND a
    // down-payment, BOTH awaiting verification on the same order. Before the
    // fix both rows took the order's current gate label and read identically
    // as "Payment Verification (Sample)"; the verifier couldn't tell them apart.
    $order = Order::create(['po_code' => 'ASH-RC6-001']);

    // Order parked at the sample gate — this is the single "current gate" that
    // used to label BOTH rows.
    $gate = OrderStage::create([
        'order_id'   => $order->id,
        'stage'      => 'payment_verification_sample',
        'sequence'   => 4,
        'status'     => OrderStage::STATUS_IN_PROGRESS,
        'started_at' => now(),
    ]);
    $order->update(['current_stage_id' => $gate->id]);

    OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 1000.00,
        'status'       => OrderPayment::STATUS_FOR_VERIFICATION,
        'uploaded_at'  => now()->subMinutes(5),
    ]);
    OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_DOWN_PAYMENT,
        'amount'       => 1000.00,
        'status'       => OrderPayment::STATUS_FOR_VERIFICATION,
        'uploaded_at'  => now(),
    ]);

    $rows = dacApprovals()->queue();
    expect($rows)->toHaveCount(2);

    // Index by payment_type so the assertion doesn't depend on row order.
    $byType = collect($rows)->keyBy('payment_type');

    expect($byType['sample']['gate'])->toBe('Payment Verification (Sample)')
        ->and($byType['down_payment']['gate'])->toBe('Payment Verification (Mass)');

    // The two labels must differ — the whole point of the fix.
    expect($byType['sample']['gate'])->not->toBe($byType['down_payment']['gate']);

    // gate_stage still carries the live workflow stage for context on both.
    expect($byType['sample']['gate_stage'])->toBe('payment_verification_sample')
        ->and($byType['down_payment']['gate_stage'])->toBe('payment_verification_sample');
});

it('still labels a balance payment correctly on the awaiting list (RC-6)', function () {
    // awaitingQueue() shares the same presenter, so the fix must hold there too.
    $order = Order::create(['po_code' => 'ASH-RC6-002']);

    OrderPayment::create([
        'order_id'     => $order->id,
        'payment_type' => OrderPayment::TYPE_BALANCE,
        'amount'       => 942.00,
        'status'       => OrderPayment::STATUS_WAITING,
        'uploaded_at'  => now(),
    ]);

    $card = dacApprovals()->awaitingQueue()[0];

    expect($card['gate'])->toBe('Payment Verification (Balance)')
        ->and($card['payment_type'])->toBe('balance');
});

it('excludes payments whose order was soft-deleted from queue() and count() (RC-5)', function () {
    // A live order with a for_verification payment — must be counted.
    $live = Order::create(['po_code' => 'ASH-RC5-LIVE']);
    OrderPayment::create([
        'order_id'     => $live->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 1000.00,
        'status'       => OrderPayment::STATUS_FOR_VERIFICATION,
        'uploaded_at'  => now(),
    ]);

    // An order that gets soft-deleted AFTER its payment exists. OrderPayment
    // has no soft-delete and there are no DB FKs, so the payment row survives.
    $trashed = Order::create(['po_code' => 'ASH-RC5-TRASH']);
    OrderPayment::create([
        'order_id'     => $trashed->id,
        'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount'       => 500.00,
        'status'       => OrderPayment::STATUS_FOR_VERIFICATION,
        'uploaded_at'  => now(),
    ]);
    $trashed->delete(); // soft delete

    // Both payment rows still physically exist...
    expect(OrderPayment::count())->toBe(2);

    // ...but only the live one surfaces, and the count agrees.
    $rows = dacApprovals()->queue();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['project_no'])->toBe('ASH-RC5-LIVE');

    expect(dacApprovals()->count())->toBe(1);
});

it('excludes payments whose order was soft-deleted from the awaiting list (RC-5)', function () {
    $live = Order::create(['po_code' => 'ASH-RC5-AW-LIVE']);
    OrderPayment::create([
        'order_id'     => $live->id,
        'payment_type' => OrderPayment::TYPE_BALANCE,
        'amount'       => 942.00,
        'status'       => OrderPayment::STATUS_WAITING,
        'uploaded_at'  => now(),
    ]);

    $trashed = Order::create(['po_code' => 'ASH-RC5-AW-TRASH']);
    OrderPayment::create([
        'order_id'     => $trashed->id,
        'payment_type' => OrderPayment::TYPE_BALANCE,
        'amount'       => 300.00,
        'status'       => OrderPayment::STATUS_REJECTED,
        'uploaded_at'  => now(),
    ]);
    $trashed->delete();

    $rows = dacApprovals()->awaitingQueue();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['project_no'])->toBe('ASH-RC5-AW-LIVE');

    expect(dacApprovals()->awaitingCount())->toBe(1);
});
