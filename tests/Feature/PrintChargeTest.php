<?php

use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Locks the silkscreen print-charge formula (Addendum §4.1, POOLED).
 *
 * Front, Back and Sleeves share ONE colour count for the whole job. The job
 * has a single first-colour base — ₱150 if ANY placement is a full print,
 * otherwise ₱100 — and that base accounts for one colour (a full one when any
 * full placement exists, else a regular one). Every REMAINING colour is then
 * charged at its own placement's rate: +₱20 regular, +₱50 full. Single-
 * placement jobs are unchanged from the old per-placement spec. No
 * pricing_settings rows are seeded, so the engine uses its documented defaults
 * (which also equal the seeded values): regular 100/20, full 150/50, special
 * print flat 20.
 */
function priceParts(array $printParts, ?int $ga = null, bool $special = false): float
{
    $service = app(QuotationService::class);

    // calculatePrintPartsTotal is protected; invoke it directly so the test
    // pins the formula itself rather than the whole quotation pipeline.
    $ref = new ReflectionMethod($service, 'calculatePrintPartsTotal');
    $ref->setAccessible(true);

    return (float) $ref->invoke($service, $printParts, $ga, $special);
}

/** Convenience: build legacy {unit_count, full_unit_count} placement rows. */
function legacyParts(array $parts): array
{
    return array_map(static fn (array $p): array => [
        'unit_count'      => $p['reg'] ?? 0,
        'full_unit_count' => $p['full'] ?? 0,
    ], $parts);
}

// Single placement — must match Brief §4.6 exactly (unchanged from prior spec).
dataset('spec_single_side', [
    'regular 0 colours' => [[['reg' => 0]], 0.0],
    'regular 1 colour'  => [[['reg' => 1]], 100.0],
    'regular 2 colours' => [[['reg' => 2]], 120.0],
    'regular 3 colours' => [[['reg' => 3]], 140.0],
    'regular 4 colours' => [[['reg' => 4]], 160.0],
    'full 1 colour'     => [[['full' => 1]], 150.0],
    'full 2 colours'    => [[['full' => 2]], 200.0],
    'full 3 colours'    => [[['full' => 3]], 250.0],
    'full 4 colours'    => [[['full' => 4]], 300.0],
]);

// Multi placement — POOLED across placements (Addendum §4.1).
dataset('multi_placement', [
    'Front 3 + Back 2, all regular' => [[['reg' => 3], ['reg' => 2]], 180.0],
    'Front full(1) + Back reg(1)'   => [[['full' => 1], ['reg' => 1]], 170.0],
    'Front full(1) + Back full(1)'  => [[['full' => 1], ['full' => 1]], 200.0],
    'Front reg(1) + Back full(1)'   => [[['reg' => 1], ['full' => 1]], 170.0],
    'Front full(2) + Back reg(1)'   => [[['full' => 2], ['reg' => 1]], 220.0],
    'Front 1 + Sleeve 1 (regular)'  => [[['reg' => 1], ['reg' => 1]], 120.0],
    'Sleeve only, 1 colour'         => [[['reg' => 1]], 100.0],
    'Front full(2) + Back empty(0)' => [[['full' => 2], ['reg' => 0]], 200.0],
]);

// New Change-12 input shape: explicit print_type + num_colors per placement.
dataset('change12_shape', [
    'regular, 3 colours'          => [[['print_type' => 'regular', 'num_colors' => 3]], 140.0],
    'full_print, 2 colours'       => [[['print_type' => 'full_print', 'num_colors' => 2]], 200.0],
    'full(1) front + reg(1) back' => [[['print_type' => 'full_print', 'num_colors' => 1], ['print_type' => 'regular', 'num_colors' => 1]], 170.0],
    'zero colours = no print'     => [[['print_type' => 'regular', 'num_colors' => 0]], 0.0],
]);

test('print-charge spec single-side cases (Brief §4.6)', function (array $parts, float $expected) {
    expect(priceParts(legacyParts($parts)))->toBe($expected);
})->with('spec_single_side');

test('print-charge multi-placement: pooled across placements (Addendum §4.1)', function (array $parts, float $expected) {
    expect(priceParts(legacyParts($parts)))->toBe($expected);
})->with('multi_placement');

test('engine reads the Change 12 shape (print_type + num_colors)', function (array $printParts, float $expected) {
    expect(priceParts($printParts))->toBe($expected);
})->with('change12_shape');

test('special print is a flat per-piece surcharge (independent of colour count)', function () {
    expect(priceParts(legacyParts([['reg' => 2]]), null, true))->toBe(140.0);              // 120 + 20
    expect(priceParts(legacyParts([['reg' => 2], ['reg' => 1]]), null, true))->toBe(160.0); // pooled 100 + 2×20, + 20
    expect(priceParts(legacyParts([['reg' => 0]]), null, true))->toBe(0.0);                 // no print, no surcharge
});

test('GA pooled override applies to a single-placement job', function () {
    expect(priceParts(legacyParts([['reg' => 1]]), 3))->toBe(140.0);   // regular(3)
    expect(priceParts(legacyParts([['full' => 1]]), 2))->toBe(200.0);  // full(2)
    expect(priceParts(legacyParts([['reg' => 1]]), 0))->toBe(0.0);     // override to 0 = no print
});

test('GA pooled override is ignored for multi-placement (per-side counts win)', function () {
    expect(priceParts(legacyParts([['reg' => 1], ['reg' => 1]]), 5))->toBe(120.0); // pooled 100 + 1×20; ga=5 ignored
});
