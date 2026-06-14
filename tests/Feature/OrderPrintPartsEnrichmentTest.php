<?php

use App\Models\Quotation;
use App\Services\OrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Order print-parts enrichment
|--------------------------------------------------------------------------
|
| The Add Order form submits a stripped print_parts payload (placement +
| colour count only). OrderService::enrichPrintPartsFromQuotation merges the
| artwork (image / image_path) and per-colour price stored on the linked
| quotation back onto those rows so the order's Print Parts table + the
| orders-list thumbnail render. The method only does Quotation::find, so we
| build just the quotations table and instantiate the service without its
| constructor (no DI graph needed).
|
| Run with:
|     php artisan test --filter=OrderPrintPartsEnrichmentTest
*/

beforeEach(function () {
    Schema::dropIfExists('quotations');
    Schema::create('quotations', function (Blueprint $table) {
        $table->id();
        $table->string('quotation_id')->unique();
        $table->json('print_parts_json')->nullable();
        $table->timestamps();
    });
});

function enrichService(): OrderService
{
    // enrichPrintPartsFromQuotation only uses Quotation::find — skip the ctor.
    return (new ReflectionClass(OrderService::class))->newInstanceWithoutConstructor();
}

function seedQuotation(array $parts): Quotation
{
    $q = new Quotation();
    $q->quotation_id = 'QUO-TEST-' . uniqid();
    $q->print_parts_json = $parts;
    $q->save();

    return $q;
}

it('merges artwork + price from the quotation onto stripped order parts', function () {
    $quote = seedQuotation([
        ['part' => 'Front', 'name' => 'Front', 'color_count' => 3, 'price_per_color' => 20,
         'image' => 'quotation-print-parts/front.png', 'image_path' => 'quotation-print-parts/front.png'],
        ['part' => 'Back', 'name' => 'Back', 'color_count' => 1, 'price_per_color' => 20,
         'image' => 'quotation-print-parts/back.png', 'image_path' => 'quotation-print-parts/back.png'],
    ]);

    // Stripped order payload — placement + colour count only, no image/price.
    $orderParts = [
        ['part' => 'Front', 'print_type' => 'regular', 'num_colors' => 3],
        ['part' => 'Back',  'print_type' => 'regular', 'num_colors' => 1],
    ];

    $out = enrichService()->enrichPrintPartsFromQuotation($orderParts, $quote->id);

    expect($out)->toHaveCount(2);
    // Artwork + price + colour count filled in from the quotation...
    expect($out[0]['image_path'])->toBe('quotation-print-parts/front.png');
    expect($out[0]['price_per_color'])->toEqual(20);
    expect($out[0]['color_count'])->toEqual(3);
    // ...while the order's own placement keys survive.
    expect($out[0]['print_type'])->toBe('regular');
    expect($out[0]['num_colors'])->toEqual(3);
    expect($out[1]['part'])->toBe('Back');
    expect($out[1]['image_path'])->toBe('quotation-print-parts/back.png');
});

it('matches parts by name regardless of order, with a positional fallback', function () {
    $quote = seedQuotation([
        ['part' => 'Back',  'price_per_color' => 15, 'image_path' => 'pp/back.png'],
        ['part' => 'Front', 'price_per_color' => 25, 'image_path' => 'pp/front.png'],
    ]);

    // Order lists Front first; name match must still pull the Front row.
    $orderParts = [
        ['part' => 'Front', 'num_colors' => 2],
        ['part' => 'Back',  'num_colors' => 1],
    ];

    $out = enrichService()->enrichPrintPartsFromQuotation($orderParts, $quote->id);

    expect($out[0]['image_path'])->toBe('pp/front.png');
    expect($out[0]['price_per_color'])->toEqual(25);
    expect($out[1]['image_path'])->toBe('pp/back.png');
});

it('returns the order parts unchanged when there is no quotation link', function () {
    $orderParts = [['part' => 'Front', 'num_colors' => 2]];

    expect(enrichService()->enrichPrintPartsFromQuotation($orderParts, null))
        ->toBe($orderParts);
});

it('returns the order parts unchanged when the quotation has no print parts', function () {
    $quote = seedQuotation([]);
    $orderParts = [['part' => 'Front', 'num_colors' => 2]];

    expect(enrichService()->enrichPrintPartsFromQuotation($orderParts, $quote->id))
        ->toBe($orderParts);
});
