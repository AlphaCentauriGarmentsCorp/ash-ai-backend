<?php

namespace Database\Seeders\Storefront;

use App\Models\Storefront\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /** Fabric copy per garment type (shown on the PDP). */
    private array $material = [
        'tee' => '100% combed cotton, 220gsm. Pre-shrunk, garment-washed.',
        'hoodie' => '380gsm cotton-poly fleece, brushed interior. Ribbed cuffs + hem.',
        'shorts' => 'Quick-dry nylon/cotton blend with side-seam pockets.',
        'underwear' => 'Combed cotton with a bonded, tagless waistband.',
        'bag' => 'Water-resistant coated canvas, reinforced stitching.',
        'socks' => 'Combed-cotton ribbed knit, reinforced heel and toe.',
    ];

    /** Fit name + copy per garment type. */
    private array $fit = [
        'tee' => ['RELAXED FIT', 'Everyday relaxed cut with a straight body. Take your true size; size up for a boxier drape.'],
        'hoodie' => ['OVERSIZED CUT', 'Dropped shoulders and a roomy body for a relaxed layer. Sits long through the torso — take your true size for oversized, size down for a cleaner fit.'],
        'shorts' => ['REGULAR FIT', 'Mid-rise with a straight leg. Sits at the hip.'],
        'underwear' => ['TRUE TO SIZE', 'Snug, supportive fit. Take your normal size.'],
        'bag' => ['ONE SIZE', 'Adjustable strap, fits crossbody or over the shoulder.'],
        'socks' => ['ONE SIZE', 'Fits EU 39–45 comfortably.'],
    ];

    public function run(): void
    {
        // audience: men | women | unisex | accessories ; type: tee|hoodie|shorts|underwear|bag|socks
        $catalog = [
            ['og-wave',     'unisex',      'tee',       'OG Wave Tee',              1200, 'BEST SELLER', ['S','M','L','XL','2XL'], 'The logo. The wave. The whole personality, front and center.'],
            ['undertow',    'men',         'tee',       'Undertow',                 1350, 'NEW',         ['M','L','XL','2XL'],     'Oversized boxy cut, back print heavy enough to pull you under.'],
            ['salt-asphalt','unisex',      'tee',       'Salt & Asphalt',           1200, 'NEW',         ['S','M','L','XL'],       'For beach kids stuck in the city. We see you.'],
            ['high-tide',   'men',         'tee',       'High Tide Club',           1450, 'HEAVYWEIGHT', ['M','L','XL','2XL'],     '240gsm cotton. Basically armor with a wave on it.'],
            ['wipeout',     'women',       'tee',       'Wipeout',                  1150, 'LAST FEW',    ['S','M','L'],            'Cropped distressed print, zero distress. Flies off the shelf.'],
            ['reef-rat',    'unisex',      'tee',       'Reef Rat',                 1300, 'NEW',         ['S','M','L','XL'],       'Pocket tee with a rat on a surfboard. No further questions.'],
            ['deep-current','unisex',      'hoodie',    'Deep Current Hoodie',      2450, 'NEW',         ['S','M','L','XL','2XL'], 'Heavyweight fleece, halftone wave across the chest. Cold-night armor.'],
            ['night-swell', 'women',       'hoodie',    'Night Swell Hoodie',       2350, 'NEW',         ['S','M','L'],            'Boxy crop hoodie, tonal print. Soft on the inside, loud on the deck.'],
            ['tide-line',   'men',         'shorts',    'Tide Line Shorts',         1250, 'NEW',         ['S','M','L','XL'],       'Mid-length nylon shorts, side print, dries before you hit the jeep.'],
            ['lagoon',      'women',       'shorts',    'Lagoon Shorts',            1200, 'NEW',         ['S','M','L'],            'High-rise cotton shorts with an embroidered hem patch.'],
            ['base-layer',  'men',         'underwear', 'Base Layer Boxers (2-pack)', 850, 'ESSENTIAL', ['S','M','L','XL'],       'Bonded-waist boxer briefs. The part nobody sees, done right.'],
            ['reef-briefs', 'women',       'underwear', 'Reef Briefs (2-pack)',      790, 'ESSENTIAL',  ['S','M','L'],            'Soft-rib cotton briefs with a woven Reefer hem tag.'],
            ['dry-bag',     'accessories', 'bag',       'Dry Sling Bag',            1100, 'NEW',         ['OS'],                   'Water-resistant roll-top sling. Phone, keys, receipts survive the swell.'],
            ['tote-swell',  'accessories', 'bag',       'Swell Tote',                750, 'STAPLE',      ['OS'],                   '16oz canvas tote, screened wave. Grocery run to gallery opening.'],
            ['crew-socks',  'accessories', 'socks',     'Reef Crew Socks (3-pack)',  480, 'STAPLE',      ['OS'],                   'Ribbed crew socks with a woven wave at the cuff. Small flex, big comfort.'],
        ];

        foreach ($catalog as $i => [$slug, $audience, $type, $name, $price, $tag, $sizes, $blurb]) {
            $product = Product::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'audience' => $audience,
                    'type' => $type,
                    'price' => $price,
                    'tag' => $tag,
                    'blurb' => $blurb,
                    'material' => $this->material[$type] ?? null,
                    'fit_name' => $this->fit[$type][0] ?? null,
                    'fit_desc' => $this->fit[$type][1] ?? null,
                    'is_active' => true,
                    'sort' => $i,
                ]
            );

            // Low stock on "LAST FEW" so the PDP shows "ONLY A FEW LEFT".
            $perSize = $tag === 'LAST FEW' ? 2 : 40;

            $slugKey = strtoupper(str_replace('-', '', $slug));
            foreach ($sizes as $size) {
                $product->variants()->updateOrCreate(
                    ['size' => $size],
                    [
                        'sku' => "REEFER-{$slugKey}-{$size}",
                        'on_hand' => $perSize,
                    ]
                );
            }
        }
    }
}
