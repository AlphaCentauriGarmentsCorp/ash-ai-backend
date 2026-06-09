<?php

use App\Services\OrderService;

/*
|--------------------------------------------------------------------------
| Per-Color auto-split — items slicing unit tests
|--------------------------------------------------------------------------
|
| Exercises OrderService::sliceItemsForColor (protected, pure) via reflection
| — no DB / DI. This is the core transform that turns the quotation's summed
| items_json into one colour's line items (matched by size name, quantity
| overridden by that colour's qty). The full convertQuotationSplit flow is
| verified manually (priced quotation -> N orders) per APPLY-AND-VERIFY.
|
| Helper name is globally unique (all Pest files load in one process).
*/

function invokeSliceItemsForColor(array $quoteItems, $colorSizes)
{
    $ref = new ReflectionClass(OrderService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $m = $ref->getMethod('sliceItemsForColor');
    $m->setAccessible(true);

    return $m->invoke($service, $quoteItems, $colorSizes);
}

it('slices matching rows, overrides quantity, and preserves price fields', function () {
    $quoteItems = [
        ['size' => 'S', 'quantity' => 10, 'price_per_piece' => 340, 'total_amount' => 3400],
        ['size' => 'M', 'quantity' => 20, 'price_per_piece' => 340, 'total_amount' => 6800],
        ['size' => 'L', 'quantity' => 5,  'price_per_piece' => 350, 'total_amount' => 1750],
    ];
    // This colour wants only S and M, with its own quantities.
    $colorSizes = [
        ['size' => 'S', 'quantity' => 4],
        ['size' => 'M', 'quantity' => 6],
        ['size' => 'L', 'quantity' => 0], // zero -> excluded
    ];

    $out = invokeSliceItemsForColor($quoteItems, $colorSizes);

    expect($out)->toHaveCount(2);
    expect($out[0]['size'])->toBe('S');
    expect($out[0]['quantity'])->toBe(4);                 // overridden
    expect($out[0]['price_per_piece'])->toBe(340);        // preserved
    expect($out[1]['size'])->toBe('M');
    expect($out[1]['quantity'])->toBe(6);
    // L (qty 0 in colour) is not carried.
    expect(collect($out)->pluck('size')->all())->not->toContain('L');
});

it('matches size names case-insensitively', function () {
    $quoteItems = [
        ['size' => 'XL', 'quantity' => 8, 'price_per_piece' => 360],
    ];
    $colorSizes = [
        ['size' => 'xl', 'quantity' => 3],
    ];

    $out = invokeSliceItemsForColor($quoteItems, $colorSizes);

    expect($out)->toHaveCount(1);
    expect($out[0]['quantity'])->toBe(3);
    expect($out[0]['price_per_piece'])->toBe(360);
});

it('adds a minimal fallback row for a colour size missing from the quote items', function () {
    $quoteItems = [
        ['size' => 'S', 'quantity' => 10, 'price_per_piece' => 340],
    ];
    $colorSizes = [
        ['size' => 'S', 'quantity' => 4],
        ['size' => '2XL', 'quantity' => 2], // not in quote items
    ];

    $out = invokeSliceItemsForColor($quoteItems, $colorSizes);

    expect($out)->toHaveCount(2);
    $byName = collect($out)->keyBy('size');
    expect($byName['S']['quantity'])->toBe(4);
    expect($byName['2XL']['quantity'])->toBe(2); // minimal fallback row
});

it('returns an empty array when the colour carries no positive quantities', function () {
    $quoteItems = [
        ['size' => 'S', 'quantity' => 10, 'price_per_piece' => 340],
    ];
    expect(invokeSliceItemsForColor($quoteItems, []))->toBe([]);
    expect(invokeSliceItemsForColor($quoteItems, [['size' => 'S', 'quantity' => 0]]))->toBe([]);
});

function invokeSampleRowsFromQuote(array $base)
{
    $ref = new ReflectionClass(OrderService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $m = $ref->getMethod('sampleRowsFromQuote');
    $m->setAccessible(true);

    return $m->invoke($service, $base);
}

it('maps the quote sample_breakdown into a single OrderSamples-shaped row', function () {
    $base = ['breakdown_json' => ['sample_breakdown' => [
        'sample_apparel'  => 'Tshirt - Premium / Boxy',
        'unit_price'      => 1000,
        'quantity'        => 1,
        'price_per_piece' => 1000,
    ]]];

    $rows = invokeSampleRowsFromQuote($base);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['size'])->toBe('Tshirt - Premium / Boxy');
    expect((float) $rows[0]['quantity'])->toBe(1.0);
    expect((float) $rows[0]['unit_price'])->toBe(1000.0);
    expect((float) $rows[0]['total_price'])->toBe(1000.0);
});

it('returns no sample rows when the quote carries no sample', function () {
    expect(invokeSampleRowsFromQuote(['breakdown_json' => []]))->toBe([]);
    expect(invokeSampleRowsFromQuote(['breakdown_json' => ['sample_breakdown' => [
        'unit_price' => 0, 'quantity' => 0, 'price_per_piece' => 0,
    ]]]))->toBe([]);
});
