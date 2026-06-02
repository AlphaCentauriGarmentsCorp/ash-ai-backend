<?php

use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Locks the silkscreen print-charge formula against the owner's authoritative
 * worked examples (Pricing Rules Addendum §4.1) and the print-charge spec.
 *
 * Model: one pooled colour count across all placements. The FIRST colour of
 * the whole job sets the base (₱100 regular, ₱150 if ANY placement is full).
 * Every remaining colour is charged at its own placement's rate: +₱20 regular,
 * +₱50 full. No pricing_settings rows are seeded, so the engine uses its
 * documented default rates — which is exactly what these cases assert.
 *
 * @param array<int,array{reg?:int,full?:int}> $parts
 */
function computePrintCharge(array $parts): float
{
    $service = app(QuotationService::class);

    // calculatePrintPartsTotal is protected; invoke it directly so the test
    // pins the formula itself rather than the whole quotation pipeline.
    $ref = new ReflectionMethod($service, 'calculatePrintPartsTotal');
    $ref->setAccessible(true);

    // Each placement carries an explicit regular/full colour split, which is
    // the precise shape the frontend sends (unit_count / full_unit_count).
    $printParts = array_map(static fn (array $p): array => [
        'unit_count'      => $p['reg'] ?? 0,
        'full_unit_count' => $p['full'] ?? 0,
    ], $parts);

    return (float) $ref->invoke($service, $printParts, null, false);
}

dataset('owner_examples', [
    'Front 3 + Back 2, all regular'      => [[['reg' => 3], ['reg' => 2]], 180.0],
    'Front full(1) + Back regular(1)'    => [[['full' => 1], ['reg' => 1]], 170.0],
    'Front full(1) + Back full(1)'       => [[['full' => 1], ['full' => 1]], 200.0],
    'Front regular(1) + Back full(1)'    => [[['reg' => 1], ['full' => 1]], 170.0],
    'Front full(2) + Back regular(1)'    => [[['full' => 2], ['reg' => 1]], 220.0],
    'Front 1 + Sleeve 1 (regular)'       => [[['reg' => 1], ['reg' => 1]], 120.0],
    'Sleeve only, 1 colour'              => [[['reg' => 1]], 100.0],
]);

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

test('owner addendum print-charge examples', function (array $parts, float $expected) {
    expect(computePrintCharge($parts))->toBe($expected);
})->with('owner_examples');

test('print-charge spec single-side cases', function (array $parts, float $expected) {
    expect(computePrintCharge($parts))->toBe($expected);
})->with('spec_single_side');
