<?php

/**
 * Quotation conversion tests — covers the new `/v2/quotations/{id}/confirm`
 * endpoint that marks a quotation Converted and returns an order_payload.
 *
 * Run with:
 *     php artisan test --filter=QuotationConversionTest
 *
 * Same isolation pattern as the other Phase tests – we build only the
 * tables we need so we don't depend on full migrations or seeders.
 */

use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    foreach (['quotation_status_logs', 'quotations', 'apparel_pattern_prices', 'apparel_types', 'pattern_types', 'print_methods', 'clients'] as $t) {
        Schema::dropIfExists($t);
    }

    // Change 6 (option B): confirmAndConvert now loads the client master to
    // carry its granular address into the order payload, so the isolated test
    // schema needs a minimal clients table + a seeded client (id = 1, which
    // makeQuotationRow references by default).
    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('contact_number')->nullable();
        $table->string('address')->nullable();
        $table->string('street_address')->nullable();
        $table->string('barangay')->nullable();
        $table->string('city')->nullable();
        $table->string('province')->nullable();
        $table->string('postal_code', 10)->nullable();
        $table->string('courier')->nullable();
        $table->string('method')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
    });

    DB::table('clients')->insert([
        'id'             => 1,
        'name'           => 'Test Client',
        'email'          => 'client@example.com',
        'contact_number' => '09171234567',
        'street_address' => '123 Rizal St',
        'barangay'       => 'Brgy Uno',
        'city'           => 'Cebu City',
        'province'       => 'Cebu',
        'postal_code'    => '6000',
        'address'        => '123 Rizal St, Brgy Uno, Cebu City, Cebu, 6000',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    Schema::create('apparel_types', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->timestamps();
    });

    Schema::create('pattern_types', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->json('images')->nullable();
        $table->timestamps();
    });

    Schema::create('print_methods', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->timestamps();
    });

    Schema::create('apparel_pattern_prices', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('apparel_type_id')->nullable();
        $table->unsignedBigInteger('pattern_type_id')->nullable();
        $table->string('apparel_type_name')->nullable();
        $table->string('pattern_type_name')->nullable();
        $table->decimal('price', 10, 2)->default(0);
        $table->timestamps();
    });

    Schema::create('quotations', function (Blueprint $table) {
        $table->id();
        $table->string('quotation_id')->unique();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('client_id')->nullable();
        $table->string('client_name')->nullable();
        $table->string('client_email')->nullable();
        $table->string('client_facebook')->nullable();
        $table->string('client_brand')->nullable();
        $table->string('shirt_color')->nullable();
        $table->unsignedBigInteger('apparel_neckline_id')->nullable();
        $table->text('free_items')->nullable();
        $table->text('notes')->nullable();
        $table->decimal('subtotal', 12, 2)->default(0);
        $table->string('discount_type')->nullable();
        $table->decimal('discount_price', 12, 2)->default(0);
        $table->decimal('discount_amount', 12, 2)->default(0);
        $table->decimal('grand_total', 12, 2)->default(0);
        $table->json('item_config_json')->nullable();
        $table->json('items_json')->nullable();
        $table->json('addons_json')->nullable();
        $table->json('breakdown_json')->nullable();
        $table->json('print_parts_json')->nullable();
        $table->string('status')->default('Pending');
        $table->timestamps();
    });

    // QuotationService writes an immutable status-log row on every transition
    // (incl. conversion). Mirror the real migration's columns so the hand-built
    // test schema doesn't 500 with "no such table: quotation_status_logs".
    Schema::create('quotation_status_logs', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('quotation_id');
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('from_status', 32)->nullable();
        $table->string('to_status', 32);
        $table->text('notes')->nullable();
        $table->boolean('email_sent')->nullable();
        $table->timestamp('created_at')->nullable();
    });
});

afterEach(function () {
    foreach (['quotation_status_logs', 'quotations', 'apparel_pattern_prices', 'apparel_types', 'pattern_types', 'print_methods', 'clients'] as $t) {
        Schema::dropIfExists($t);
    }
});

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function makeQuotationRow(array $overrides = []): Quotation
{
    return Quotation::create(array_merge([
        'quotation_id' => 'QUO-2026-' . substr(uniqid(), -6),
        'user_id'      => null,
        'client_id'    => 1,
        'client_name'  => 'Test Client',
        'client_brand' => 'TestBrand',
        'shirt_color'  => 'Black',
        'apparel_neckline_id' => null,
        'free_items'   => null,
        'notes'        => 'Demo notes',
        'subtotal'     => 1000,
        'discount_type'   => 'fixed',
        'discount_price'  => 0,
        'discount_amount' => 0,
        'grand_total'  => 1000,
        'status'       => 'Pending',
    ], $overrides));
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('returns an order_payload and marks the quotation as Converted', function () {
    // Seed lookup tables
    $apparelTypeId = DB::table('apparel_types')->insertGetId([
        'name' => 'T-Shirt', 'description' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $patternTypeId = DB::table('pattern_types')->insertGetId([
        'name' => 'Crew Neck', 'description' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $appPatPriceId = DB::table('apparel_pattern_prices')->insertGetId([
        'apparel_type_id'   => $apparelTypeId,
        'pattern_type_id'   => $patternTypeId,
        'apparel_type_name' => 'T-Shirt',
        'pattern_type_name' => 'Crew Neck',
        'price'             => 250,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $printMethodId = DB::table('print_methods')->insertGetId([
        'name' => 'Silkscreen', 'description' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $quotation = makeQuotationRow([
        'item_config_json' => [
            'apparel_pattern_price_id' => $appPatPriceId,
            'apparel_type_id'          => $apparelTypeId,
            'pattern_type_id'          => $patternTypeId,
            'print_method_id'          => $printMethodId,
            'special_print'            => 'metallic',
            'print_area'               => 'front-only',
        ],
        'items_json'  => [['size' => 'M', 'quantity' => 10, 'unit_price' => 100]],
        'addons_json' => [],
    ]);

    /** @var QuotationService $svc */
    $svc = app(QuotationService::class);
    $result = $svc->confirmAndConvert($quotation->id);

    // Quotation status flipped
    expect($result['quotation']->status)->toBe('Converted');
    expect($quotation->fresh()->status)->toBe('Converted');

    // Payload is well-shaped
    $payload = $result['order_payload'];
    expect($payload)->toHaveKey('quotation_id');
    expect($payload['quotation_id'])->toBe($quotation->id);

    // Human-facing code carried separately (display), numeric id stays the FK
    expect($payload)->toHaveKey('quotation_code');
    expect($payload['quotation_code'])->toBe($quotation->quotation_id);
    expect($payload['quotation_code'])->toStartWith('QUO-');

    // Client fields carried over
    expect($payload['client_id'])->toBe(1);
    expect($payload['client_brand'])->toBe('TestBrand');
    expect($payload['client_name'])->toBe('Test Client');

    // Apparel + pattern + print method names resolved
    expect($payload['apparel_type_id'])->toBe($apparelTypeId);
    expect($payload['apparel_type_name'])->toBe('T-Shirt');
    expect($payload['pattern_type_id'])->toBe($patternTypeId);
    expect($payload['pattern_type_name'])->toBe('Crew Neck');
    expect($payload['print_method_id'])->toBe($printMethodId);
    expect($payload['print_method_name'])->toBe('Silkscreen');

    // Misc descriptive
    expect($payload['shirt_color'])->toBe('Black');
    expect($payload['special_print'])->toBe('metallic');
    expect($payload['print_area'])->toBe('front-only');

    // Financials
    expect((float) $payload['subtotal'])->toBe(1000.0);
    expect((float) $payload['grand_total'])->toBe(1000.0);

    // JSON blobs preserved
    expect($payload['items_json'])->toBeArray();
    expect($payload['items_json'][0]['size'])->toBe('M');
    expect($payload['item_config_json']['apparel_pattern_price_id'])->toBe($appPatPriceId);
});

it('refuses with 409 when the quotation is already converted', function () {
    $quotation = makeQuotationRow(['status' => 'Converted']);

    /** @var QuotationService $svc */
    $svc = app(QuotationService::class);

    expect(fn () => $svc->confirmAndConvert($quotation->id))
        ->toThrow(HttpException::class);
});

it('falls back to direct apparel/pattern lookup when no apparel_pattern_price_id is present', function () {
    $apparelTypeId = DB::table('apparel_types')->insertGetId([
        'name' => 'Hoodie', 'description' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $patternTypeId = DB::table('pattern_types')->insertGetId([
        'name' => 'Pullover', 'description' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $quotation = makeQuotationRow([
        'item_config_json' => [
            // No apparel_pattern_price_id here
            'apparel_type_id' => $apparelTypeId,
            'pattern_type_id' => $patternTypeId,
        ],
    ]);

    /** @var QuotationService $svc */
    $svc = app(QuotationService::class);
    $result = $svc->confirmAndConvert($quotation->id);

    $payload = $result['order_payload'];
    expect($payload['apparel_type_name'])->toBe('Hoodie');
    expect($payload['pattern_type_name'])->toBe('Pullover');
});

it('handles missing item_config_json gracefully', function () {
    // Quotation with no item_config — payload should still come back, just with nulls
    $quotation = makeQuotationRow(['item_config_json' => null]);

    /** @var QuotationService $svc */
    $svc = app(QuotationService::class);
    $result = $svc->confirmAndConvert($quotation->id);

    $payload = $result['order_payload'];
    expect($payload['apparel_type_id'])->toBeNull();
    expect($payload['apparel_type_name'])->toBeNull();
    expect($payload['pattern_type_id'])->toBeNull();
    expect($payload['print_method_id'])->toBeNull();

    // Quotation still gets marked Converted
    expect($quotation->fresh()->status)->toBe('Converted');
});

it('carries the client master granular address into the order payload (Change 6)', function () {
    // The client (id = 1) is seeded in beforeEach with a full granular address.
    $quotation = makeQuotationRow();

    /** @var QuotationService $svc */
    $svc = app(QuotationService::class);
    $payload = $svc->confirmAndConvert($quotation->id)['order_payload'];

    // Mapped onto the order form's shipping-block keys.
    expect($payload['receiver_name'])->toBe('Test Client');
    expect($payload['contact_number'])->toBe('09171234567');
    expect($payload['street_address'])->toBe('123 Rizal St');
    expect($payload['barangay_address'])->toBe('Brgy Uno');
    expect($payload['city_address'])->toBe('Cebu City');
    expect($payload['province_address'])->toBe('Cebu');
    expect($payload['postal_address'])->toBe('6000');
});

it('returns null address keys when the quotation has no linked client (Change 6)', function () {
    $quotation = makeQuotationRow(['client_id' => null]);

    /** @var QuotationService $svc */
    $svc = app(QuotationService::class);
    $payload = $svc->confirmAndConvert($quotation->id)['order_payload'];

    expect($payload['street_address'])->toBeNull();
    expect($payload['city_address'])->toBeNull();
    expect($payload['postal_address'])->toBeNull();
});