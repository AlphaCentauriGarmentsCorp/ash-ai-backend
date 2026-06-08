<?php

/**
 * Change 5 — QuotationPdfAssets: image resolution + asset collection for the
 * quotation PDF.
 *
 * Run with:
 *     php artisan test --filter=QuotationPdfAssetsTest
 *
 * Hand-built schema + a fake `public` disk so we don't depend on full
 * migrations/seeders or real files.
 */

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Quotation;
use App\Support\QuotationPdfAssets;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');

    foreach (['order_payments', 'orders', 'quotations'] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('quotations', function (Blueprint $table) {
        $table->id();
        $table->string('quotation_id')->unique();
        $table->json('print_parts_json')->nullable();
        $table->string('label_design_path', 1000)->nullable();
        $table->string('custom_pattern_image')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
    });

    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('quotation_id')->nullable();
        $table->softDeletes(); // Order uses SoftDeletes -> the query filters on deleted_at
        $table->timestamps();
    });

    Schema::create('order_payments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('order_id');
        $table->string('payment_type')->nullable();
        $table->decimal('amount', 12, 2)->nullable();
        $table->string('reference_number')->nullable();
        $table->string('proof_path')->nullable();
        $table->string('status')->nullable();
        $table->timestamp('verified_at')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    foreach (['order_payments', 'orders', 'quotations'] as $t) {
        Schema::dropIfExists($t);
    }
});

function putFake(string $path): string
{
    Storage::disk('public')->put($path, 'fake-image-bytes');
    return $path;
}

function makeQuo(array $overrides = []): Quotation
{
    return Quotation::create(array_merge([
        'quotation_id'         => 'QUO-' . substr(uniqid(), -6),
        'print_parts_json'     => [],
        'label_design_path'    => null,
        'custom_pattern_image' => null,
        'status'               => 'Pending',
    ], $overrides));
}

// ── resolve() ────────────────────────────────────────────────────────────

it('resolves a stored disk path to a base64 data URI', function () {
    putFake('mock/front.png');
    expect(QuotationPdfAssets::resolve('mock/front.png'))
        ->toStartWith('data:image/png;base64,');
});

it('normalises a /storage/ prefixed path', function () {
    putFake('mock/front.jpg');
    expect(QuotationPdfAssets::resolve('/storage/mock/front.jpg'))
        ->toStartWith('data:image/jpeg;base64,');
});

it('returns null for missing / remote / empty, and passes data URIs through', function () {
    expect(QuotationPdfAssets::resolve('missing/x.png'))->toBeNull();
    expect(QuotationPdfAssets::resolve('https://example.com/x.png'))->toBeNull();
    expect(QuotationPdfAssets::resolve(''))->toBeNull();
    expect(QuotationPdfAssets::resolve(null))->toBeNull();
    expect(QuotationPdfAssets::resolve('data:image/png;base64,AAAA'))
        ->toBe('data:image/png;base64,AAAA');
});

// ── mockups + design assets ────────────────────────────────────────────────

it('collects per-placement mockups and the shared design assets', function () {
    putFake('mock/front.png');
    putFake('mock/back.jpg');
    putFake('design/label.png');

    $quo = makeQuo([
        'print_parts_json' => [
            ['part' => 'Front', 'num_colors' => 4, 'image_path' => 'mock/front.png'],
            ['part' => 'Back', 'image' => 'mock/back.jpg'],
            ['part' => 'Sleeve'], // no image -> skipped
        ],
        'label_design_path' => 'design/label.png',
    ]);

    $data = QuotationPdfAssets::for($quo);

    expect($data['mockups'])->toHaveCount(2);
    expect($data['mockups'][0]['label'])->toBe('Front');
    expect($data['mockups'][0]['meta'])->toBe('4 colors');
    expect($data['mockups'][0]['src'])->toStartWith('data:image/png;base64,');
    expect($data['mockups'][1]['label'])->toBe('Back');
    expect($data['mockups'][1]['meta'])->toBeNull();

    expect($data['designAssets'])->toHaveCount(1);
    expect($data['designAssets'][0]['label'])->toBe('Label Design');
});

// ── forward payment-proof lookup ─────────────────────────────────────────────

it('reaches forward to the converted order for VERIFIED payment proofs only', function () {
    $quo = makeQuo(['status' => 'Converted']);
    $order = Order::create(['quotation_id' => $quo->id]);

    putFake('pay/sample.jpg');
    putFake('pay/down.jpg');
    putFake('pay/balance.jpg');

    OrderPayment::create([
        'order_id' => $order->id, 'payment_type' => OrderPayment::TYPE_SAMPLE,
        'amount' => 500, 'reference_number' => 'REF-S', 'proof_path' => 'pay/sample.jpg',
        'status' => OrderPayment::STATUS_VERIFIED, 'verified_at' => now(),
    ]);
    OrderPayment::create([
        'order_id' => $order->id, 'payment_type' => OrderPayment::TYPE_DOWN_PAYMENT,
        'amount' => 6000, 'reference_number' => 'REF-D', 'proof_path' => 'pay/down.jpg',
        'status' => OrderPayment::STATUS_VERIFIED, 'verified_at' => now(),
    ]);
    // Not verified yet -> excluded.
    OrderPayment::create([
        'order_id' => $order->id, 'payment_type' => OrderPayment::TYPE_BALANCE,
        'amount' => 4000, 'proof_path' => 'pay/balance.jpg',
        'status' => OrderPayment::STATUS_FOR_VERIFICATION,
    ]);
    // Verified but no proof -> excluded.
    OrderPayment::create([
        'order_id' => $order->id, 'payment_type' => OrderPayment::TYPE_FULL,
        'amount' => 100, 'proof_path' => null,
        'status' => OrderPayment::STATUS_VERIFIED, 'verified_at' => now(),
    ]);

    $proofs = QuotationPdfAssets::for($quo)['paymentProofs'];

    expect($proofs)->toHaveCount(2);
    expect($proofs[0]['label'])->toBe('Sample Payment');
    expect((float) $proofs[0]['amount'])->toBe(500.0);
    expect($proofs[0]['ref'])->toBe('REF-S');
    expect($proofs[0]['src'])->toStartWith('data:image/jpeg;base64,');
    expect($proofs[1]['label'])->toBe('Down Payment (60%)');
});

it('omits every section cleanly when nothing is present', function () {
    $data = QuotationPdfAssets::for(makeQuo());

    expect($data['mockups'])->toBe([]);
    expect($data['designAssets'])->toBe([]);
    expect($data['paymentProofs'])->toBe([]);
    expect($data['quotation'])->toBeInstanceOf(Quotation::class);
});