<?php

use App\Models\ApparelPatternPrice;

/**
 * Guards the size-label matching in priceForSize(). The quotation UI uses short
 * labels (S/M/L) while seeded size_prices use long labels (Small/Medium/Large);
 * these must resolve to the same price instead of silently falling back to the
 * single base price.
 */
function patternWith(array $sizePrices, float $base): ApparelPatternPrice
{
    $m = new ApparelPatternPrice();
    $m->price = $base;
    $m->size_prices = $sizePrices;
    return $m;
}

it('matches short size labels against long seeded keys', function () {
    // Mirrors the seeded "Tshirt - Premium / Standard" map.
    $p = patternWith([
        'Small' => 200, 'Medium' => 200,
        'Large' => 210, 'XL' => 210,
        '2XL' => 230, '3XL' => 230,
    ], 250);

    expect($p->priceForSize('S'))->toBe(200.0);    // was falling back to 250
    expect($p->priceForSize('M'))->toBe(200.0);
    expect($p->priceForSize('L'))->toBe(210.0);    // was falling back to 250
    expect($p->priceForSize('XL'))->toBe(210.0);
    expect($p->priceForSize('2XL'))->toBe(230.0);
    expect($p->priceForSize('3XL'))->toBe(230.0);
});

it('falls back to the base price only for genuinely unlisted sizes', function () {
    $p = patternWith(['Small' => 200, 'Large' => 210], 250);
    // XS is not in the map at all → base price.
    expect($p->priceForSize('XS'))->toBe(250.0);
});

it('matches regardless of spelling/spacing/case', function () {
    $p = patternWith(['extra large' => 300, 'XXL' => 360], 100);
    expect($p->priceForSize('XL'))->toBe(300.0);
    expect($p->priceForSize('2xl'))->toBe(360.0);
});
