<?php

/**
 * Issue 20 — Supplier order channels.
 *
 * Run with:
 *   php artisan test --filter=SupplierOrderChannelTest
 *
 * Coverage (service + resource level; mirrors the existing MaterialPrepPortal
 * pattern of exercising the service directly against a throwaway schema):
 *   1. create() normalizes channels — none flagged primary → first wins
 *   2. create() normalizes channels — multiple flagged → only the first stays
 *   3. create() drops url-less rows and coerces unknown types to 'other'
 *   4. create() with a minimal payload (no granular address) does not error
 *   5. quickCreate() flags is_incomplete + single primary channel
 *   6. quickCreate() with no channel → is_incomplete + empty channels
 *   7. SupplierResource exposes order_channels + is_incomplete
 */

use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('suppliers');

    Schema::create('suppliers', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('contact_person')->nullable();
        $t->string('contact_number')->nullable();
        $t->string('email')->nullable();
        $t->string('address')->nullable();
        $t->text('notes')->nullable();
        $t->text('order_channels')->nullable();   // 'array' cast (json on live MySQL)
        $t->boolean('is_incomplete')->default(false);
        $t->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('suppliers');
});

function issue20_service(): SupplierService
{
    return new SupplierService();
}

function issue20_baseData(array $overrides = []): array
{
    return array_merge([
        'name'           => 'Tela Supplier',
        'contact_person' => 'Kuya Test',
        'contact_number' => '0917 000 0000',
        'street_address' => '123 Divisoria',
        'barangay'       => 'Brgy 1',
        'city'           => 'Manila',
        'province'       => 'NCR',
        'postal_code'    => '1000',
    ], $overrides);
}

it('makes the first row primary when none is flagged', function () {
    $supplier = issue20_service()->create(issue20_baseData([
        'order_channels' => [
            ['type' => 'viber',  'url' => 'viber://a'],
            ['type' => 'shopee', 'url' => 'https://shopee/x'],
        ],
    ]));

    $channels = $supplier->fresh()->order_channels;
    expect($channels)->toHaveCount(2);
    expect($channels[0]['is_primary'])->toBeTrue();
    expect($channels[1]['is_primary'])->toBeFalse();
});

it('keeps only the first flagged primary when several are marked', function () {
    $supplier = issue20_service()->create(issue20_baseData([
        'order_channels' => [
            ['type' => 'viber',  'url' => 'viber://a', 'is_primary' => false],
            ['type' => 'shopee', 'url' => 'https://shopee/x', 'is_primary' => true],
            ['type' => 'lazada', 'url' => 'https://lazada/y', 'is_primary' => true],
        ],
    ]));

    $channels = $supplier->fresh()->order_channels;
    $primaries = collect($channels)->where('is_primary', true);
    expect($primaries)->toHaveCount(1);
    expect($primaries->first()['type'])->toBe('shopee');
});

it('drops url-less rows and coerces unknown channel types to other', function () {
    $supplier = issue20_service()->create(issue20_baseData([
        'order_channels' => [
            ['type' => 'viber',    'url' => 'viber://a'],
            ['type' => 'shopee',   'url' => ''],            // dropped (no url)
            ['type' => 'myspace',  'url' => 'https://m/z'], // unknown → other
        ],
    ]));

    $channels = $supplier->fresh()->order_channels;
    expect($channels)->toHaveCount(2);
    expect(collect($channels)->pluck('type')->all())->toBe(['viber', 'other']);
});

it('creates a supplier with a minimal payload without erroring on address', function () {
    $supplier = issue20_service()->create([
        'name'           => 'Bare Supplier',
        'contact_person' => 'X',
        'contact_number' => '0900',
    ]);

    expect($supplier->exists)->toBeTrue();
    expect($supplier->address)->toBe('||||'); // five empty parts joined by '|'
});

it('quick-adds an incomplete supplier with a single primary channel', function () {
    $supplier = issue20_service()->quickCreate([
        'name'         => 'Divisoria Tela',
        'channel_type' => 'viber',
        'channel_url'  => 'viber://chat?x',
    ]);

    $fresh = $supplier->fresh();
    expect($fresh->is_incomplete)->toBeTrue();
    expect($fresh->contact_person)->toBe('');
    expect($fresh->order_channels)->toHaveCount(1);
    expect($fresh->order_channels[0]['is_primary'])->toBeTrue();
    expect($fresh->order_channels[0]['type'])->toBe('viber');
});

it('quick-adds with no channel and stores an empty channels array', function () {
    $supplier = issue20_service()->quickCreate(['name' => 'No Link Supplier']);

    $fresh = $supplier->fresh();
    expect($fresh->is_incomplete)->toBeTrue();
    expect($fresh->order_channels)->toBe([]);
});

it('exposes order_channels and is_incomplete through the resource', function () {
    $supplier = issue20_service()->create(issue20_baseData([
        'order_channels' => [['type' => 'website', 'url' => 'https://x']],
    ]));

    $array = (new SupplierResource($supplier->fresh()))->toArray(request());

    expect($array)->toHaveKey('order_channels');
    expect($array)->toHaveKey('is_incomplete');
    expect($array['is_incomplete'])->toBeFalse();
    expect($array['order_channels'][0]['type'])->toBe('website');
    expect($array['order_channels'][0]['is_primary'])->toBeTrue();
});
