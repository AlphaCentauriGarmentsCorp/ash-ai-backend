<?php

use App\Services\QuotationService;

/*
|--------------------------------------------------------------------------
| Per-Color Quantity Breakdown — normalization unit tests
|--------------------------------------------------------------------------
|
| Exercises the two pure helpers added to QuotationService for the Per-Color
| Quantity Breakdown feature, via reflection (no DB / DI needed). These guard
| the breakdown_json['color_breakdowns'] shape that the PDF reads and the
| derived scalar shirt_color used for backward compat.
|
| Helper name is globally unique (all Pest files load in one process).
*/

function invokeQuotationColorHelper(string $method, ...$args)
{
    // The methods under test don't use the constructor dependency, so build
    // the instance without invoking the constructor to avoid wiring DI.
    $ref = new ReflectionClass(QuotationService::class);
    $service = $ref->newInstanceWithoutConstructor();

    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke($service, ...$args);
}

it('normalizes nested color groups and computes per-color subtotals', function () {
    $input = [
        ['color' => 'Black', 'sizes' => [
            ['size' => 'S', 'quantity' => 10],
            ['size' => 'M', 'quantity' => 20],
        ]],
        ['color' => 'White', 'sizes' => [
            ['size' => 'L', 'quantity' => 5],
        ]],
    ];

    $out = invokeQuotationColorHelper('normalizeColorBreakdowns', $input);

    expect($out)->toHaveCount(2);
    expect($out[0]['color'])->toBe('Black');
    expect($out[0]['subtotal_qty'])->toBe(30);
    expect($out[0]['sizes'])->toHaveCount(2);
    expect($out[1]['color'])->toBe('White');
    expect($out[1]['subtotal_qty'])->toBe(5);
});

it('coerces quantities to non-negative integers and drops empty size rows', function () {
    $input = [
        ['color' => 'Red', 'sizes' => [
            ['size' => 'S', 'quantity' => '12'],     // string -> int
            ['size' => 'M', 'quantity' => 3.9],      // float -> rounded int
            ['size' => '', 'quantity' => 0],         // empty row -> dropped
            ['size' => 'L', 'quantity' => -4],       // negative -> clamped 0
        ]],
    ];

    $out = invokeQuotationColorHelper('normalizeColorBreakdowns', $input);

    expect($out)->toHaveCount(1);
    $sizes = $out[0]['sizes'];
    expect($sizes)->toHaveCount(3); // empty row dropped
    expect($sizes[0])->toBe(['size' => 'S', 'quantity' => 12]);
    expect($sizes[1])->toBe(['size' => 'M', 'quantity' => 4]);
    expect($sizes[2])->toBe(['size' => 'L', 'quantity' => 0]);
    expect($out[0]['subtotal_qty'])->toBe(16); // 12 + 4 + 0
});

it('keeps a named group with no rows but drops a fully-empty group', function () {
    $input = [
        ['color' => 'Navy', 'sizes' => []],          // named, no rows -> kept
        ['color' => '', 'sizes' => []],              // nothing -> dropped
        ['color' => '', 'sizes' => [['size' => 'XL', 'quantity' => 7]]], // unnamed but has qty -> kept
    ];

    $out = invokeQuotationColorHelper('normalizeColorBreakdowns', $input);

    expect($out)->toHaveCount(2);
    expect($out[0]['color'])->toBe('Navy');
    expect($out[0]['sizes'])->toBe([]);
    expect($out[1]['color'])->toBe('');
    expect($out[1]['subtotal_qty'])->toBe(7);
});

it('returns an empty array for non-array / legacy input', function () {
    expect(invokeQuotationColorHelper('normalizeColorBreakdowns', null))->toBe([]);
    expect(invokeQuotationColorHelper('normalizeColorBreakdowns', 'not-an-array'))->toBe([]);
    expect(invokeQuotationColorHelper('normalizeColorBreakdowns', []))->toBe([]);
});

it('derives a comma-joined shirt_color from distinct group colors in order', function () {
    $groups = [
        ['color' => 'Black', 'sizes' => []],
        ['color' => 'White', 'sizes' => []],
        ['color' => 'Black', 'sizes' => []], // duplicate -> de-duped
    ];

    expect(invokeQuotationColorHelper('deriveShirtColor', $groups))
        ->toBe('Black, White');
});

it('returns null shirt_color when no group is named (caller keeps fallback)', function () {
    $groups = [
        ['color' => '', 'sizes' => [['size' => 'M', 'quantity' => 5]]],
    ];

    expect(invokeQuotationColorHelper('deriveShirtColor', $groups))->toBeNull();
    expect(invokeQuotationColorHelper('deriveShirtColor', []))->toBeNull();
});
