<?php

use App\Services\OrderService;

/*
|--------------------------------------------------------------------------
| Sample fold — order Grand Total includes the sample
|--------------------------------------------------------------------------
|
| OrderService::foldSampleIntoTotals adds the sample onto the sample-free
| production subtotal and recomputes the discount + 60/40 split, so the order
| Grand Total matches the quotation. Pure function — no DB / DI needed.
|
| Run with:
|     php artisan test --filter=OrderSampleFoldTest
*/

function foldService(): OrderService
{
    return (new ReflectionClass(OrderService::class))->newInstanceWithoutConstructor();
}

it('folds a sample into the subtotal, grand total and 60/40 split', function () {
    $out = foldService()->foldSampleIntoTotals(
        7070.0,
        [['size' => 'Hoodie - Heavyweight / Boxy', 'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000]],
        'percentage',
        0.0,
    );

    expect($out['subtotal'])->toEqual(8070.0);
    expect($out['grand_total'])->toEqual(8070.0);
    expect($out['downpayment'])->toEqual(4842.0);
    expect($out['balance'])->toEqual(3228.0);
    expect($out['sample_breakdown']['price_per_piece'])->toEqual(1000.0);
    expect($out['sample_breakdown']['sample_apparel'])->toBe('Hoodie - Heavyweight / Boxy');
});

it('applies a percentage discount to the sample-inclusive subtotal', function () {
    $out = foldService()->foldSampleIntoTotals(
        9000.0,
        [['size' => 'Tee', 'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000]],
        'percentage',
        10.0,
    );

    // subtotal 10000, 10% off = 1000, grand 9000
    expect($out['subtotal'])->toEqual(10000.0);
    expect($out['discount_amount'])->toEqual(1000.0);
    expect($out['grand_total'])->toEqual(9000.0);
    expect($out['downpayment'])->toEqual(5400.0);
    expect($out['balance'])->toEqual(3600.0);
});

it('sums multiple sample rows into one sample total', function () {
    $out = foldService()->foldSampleIntoTotals(
        5000.0,
        [
            ['size' => 'A', 'total_price' => 500],
            ['size' => 'B', 'total_price' => 300],
        ],
        null,
        0.0,
    );

    expect($out['grand_total'])->toEqual(5800.0);
    expect($out['sample_breakdown']['price_per_piece'])->toEqual(800.0);
});

it('returns null when there is no sample to fold', function () {
    expect(foldService()->foldSampleIntoTotals(5000.0, [], null, 0.0))->toBeNull();
    expect(foldService()->foldSampleIntoTotals(5000.0, [['size' => 'X', 'total_price' => 0]], null, 0.0))->toBeNull();
});
