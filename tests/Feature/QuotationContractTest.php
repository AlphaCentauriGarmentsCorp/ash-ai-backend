<?php

use App\Models\ApparelNeckline;
use App\Models\ApparelType;
use App\Models\ApparelPatternPrice;
use App\Models\PatternType;
use App\Models\PrintMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function quotationPayload(array $overrides = []): array
{
    $suffix = uniqid();

    $apparelType = ApparelType::create([
        'name' => 'T-Shirt-' . $suffix,
        'description' => 'T-Shirt description',
    ]);

    $patternType = PatternType::create([
        'name' => 'Boxy-' . $suffix,
        'description' => 'Boxy description',
        'images' => [],
    ]);

    $pattern = ApparelPatternPrice::create([
        'apparel_type_name' => 'tshirt-' . $suffix,
        'pattern_type_name' => 'boxy-' . $suffix,
        'price' => 100,
    ]);

    $neckline = ApparelNeckline::create([
        'name' => 'Round Neck',
        'price' => 20,
    ]);

    $printMethod = PrintMethod::create([
        'name' => 'Silkscreen',
        'description' => 'Silkscreen method',
    ]);

    $base = [
        'client_name' => 'Client A',
        'client_email' => 'client@example.com',
        'client_brand' => 'Brand A',
        'shirt_color' => 'Black',
        'apparel_neckline_id' => $neckline->id,
        'print_method_id' => $printMethod->id,
        'special_print' => 'Puff',
        'print_area' => 'Chest',
        'discount_type' => 'percentage',
        'discount_price' => 10,
        'item_config_json' => json_encode([
            'apparel_pattern_price_id' => $pattern->id,
            'apparel_type_id' => $apparelType->id,
            'pattern_type_id' => $patternType->id,
        ]),
        'items_json' => json_encode([
            ['id' => 1, 'size_id' => 1, 'size' => 'M', 'quantity' => 2, 'unit_price' => 5],
        ]),
        'addons_json' => json_encode([
            ['name' => 'Label', 'price' => 30],
        ]),
        'breakdown_json' => json_encode([
            'items' => [['size' => 'M', 'qty' => 2]],
            'sample_breakdown' => [
                'sample_apparel' => 'Sample Tee',
                'unit_price' => 10,
                'quantity' => 2,
                'price_per_piece' => 25,
            ],
        ]),
        'print_parts_json' => json_encode([
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 2,
                'price_per_unit' => 15,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'link',
                'image_link' => 'https://example.com/front.png',
            ],
        ]),
    ];

    return array_replace($base, $overrides);
}

function actingAshUser(): User
{
    Permission::firstOrCreate(['name' => 'access.quotations', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'username' => 'ash_' . uniqid(),
        'domain_role' => ['admin'],
        'domain_access' => ['ash'],
    ]);

    $user->givePermissionTo('access.quotations');
    $user->assignRole('admin');

    test()->actingAs($user, 'sanctum');

    return $user;
}

it('creates and updates quotation with compact item_config and items', function () {
    Storage::fake('public');
    Mail::fake();
    actingAshUser();

    $createPayload = quotationPayload();

    $createResponse = $this->withHeader('Accept', 'application/json')->post('/api/v2/quotations', $createPayload);
    $createResponse->assertStatus(201);

    $createResponse->assertJsonPath('data.item_config.apparel_pattern_price_id', json_decode($createPayload['item_config_json'], true)['apparel_pattern_price_id']);
    $createResponse->assertJsonPath('data.items.0.price_per_piece', 155);
    $createResponse->assertJsonPath('data.items.0.total_amount', 310);
    $createResponse->assertJsonPath('data.print_parts.0.part_id', 1);
    $createResponse->assertJsonPath('data.print_parts.0.price_per_unit', 15);
    $createResponse->assertJsonPath('data.print_parts.0.print_part_total', 30);
    $createResponse->assertJsonPath('data.print_method_id', $createPayload['print_method_id']);
    $createResponse->assertJsonPath('data.special_print', 'Puff');
    $createResponse->assertJsonPath('data.print_area', 'Chest');
    $createResponse->assertJsonPath('data.breakdown.print_parts_total', 60);
    $createResponse->assertJsonPath('data.breakdown.print_parts_unit_total', 30);
    $createResponse->assertJsonPath('data.subtotal', 365);
    $createResponse->assertJsonPath('data.discount_amount', 36.5);
    $createResponse->assertJsonPath('data.grand_total', 328.5);

    $quotationId = $createResponse->json('data.id');

    $updatePayload = quotationPayload([
        'items_json' => json_encode([
            ['id' => 1, 'size_id' => 1, 'size' => 'M', 'quantity' => 3, 'unit_price' => 10],
        ]),
        'discount_type' => 'fixed',
        'discount_price' => 50,
    ]);

    $updateResponse = $this->withHeader('Accept', 'application/json')->put('/api/v2/quotations/' . $quotationId, $updatePayload);
    $updateResponse->assertOk();

    $updateResponse->assertJsonPath('data.items.0.price_per_piece', 160);
    $updateResponse->assertJsonPath('data.items.0.total_amount', 480);
    $updateResponse->assertJsonPath('data.print_parts.0.print_part_total', 30);
    $updateResponse->assertJsonPath('data.breakdown.print_parts_total', 90);
    $updateResponse->assertJsonPath('data.breakdown.print_parts_unit_total', 30);
    $updateResponse->assertJsonPath('data.subtotal', 535);
    $updateResponse->assertJsonPath('data.discount_amount', 50);
    $updateResponse->assertJsonPath('data.grand_total', 485);
});

it('handles print parts in file mode', function () {
    Storage::fake('public');
    Mail::fake();
    actingAshUser();

    $payload = quotationPayload([
        'print_parts_json' => json_encode([
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 2,
                'price_per_unit' => 15,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'file',
                'image_link' => '',
            ],
        ]),
        'print_parts_files' => [UploadedFile::fake()->create('front.png', 64, 'image/png')],
    ]);

    $response = $this->withHeader('Accept', 'application/json')->post('/api/v2/quotations', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.print_parts.0.image_input_type', 'file');

    expect($response->json('data.print_parts.0.image'))->not->toBeNull();
    expect($response->json('data.print_parts.0.print_part_total'))->toBe(30);
});

it('handles print parts in link mode', function () {
    Storage::fake('public');
    Mail::fake();
    actingAshUser();

    $payload = quotationPayload([
        'print_parts_json' => json_encode([
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 2,
                'price_per_unit' => 15,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'link',
                'image_link' => 'https://example.com/front.png',
            ],
        ]),
    ]);

    $response = $this->withHeader('Accept', 'application/json')->post('/api/v2/quotations', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.print_parts.0.image_input_type', 'link')
        ->assertJsonPath('data.print_parts.0.image_link', 'https://example.com/front.png')
        ->assertJsonPath('data.print_parts.0.image', null)
        ->assertJsonPath('data.print_parts.0.print_part_total', 30);
});

it('handles mixed file and link print parts', function () {
    Storage::fake('public');
    Mail::fake();
    actingAshUser();

    $payload = quotationPayload([
        'print_parts_json' => json_encode([
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 2,
                'price_per_unit' => 15,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'file',
                'image_link' => '',
            ],
            [
                'part_id' => 2,
                'part' => 'Back',
                'unit_count' => 3,
                'price_per_unit' => 10,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'link',
                'image_link' => 'https://example.com/back.png',
            ],
        ]),
        'print_parts_files' => [UploadedFile::fake()->create('front.png', 64, 'image/png')],
    ]);

    $response = $this->withHeader('Accept', 'application/json')->post('/api/v2/quotations', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.print_parts.0.image_input_type', 'file')
        ->assertJsonPath('data.print_parts.1.image_input_type', 'link')
        ->assertJsonPath('data.print_parts.1.image_link', 'https://example.com/back.png')
        ->assertJsonPath('data.print_parts.0.print_part_total', 30)
        ->assertJsonPath('data.print_parts.1.print_part_total', 30);

    expect($response->json('data.print_parts.0.image'))->not->toBeNull();
});

it('allows empty special_print for silkscreen', function () {
    Storage::fake('public');
    Mail::fake();
    actingAshUser();

    $payload = quotationPayload([
        'special_print' => '',
    ]);

    $response = $this->withHeader('Accept', 'application/json')->post('/api/v2/quotations', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.print_method_id', $payload['print_method_id'])
        ->assertJsonPath('data.special_print', null);
});

it('returns 422 for malformed json fields', function () {
    Storage::fake('public');
    Mail::fake();
    actingAshUser();

    $fields = ['item_config_json', 'items_json', 'addons_json', 'breakdown_json', 'print_parts_json'];

    foreach ($fields as $field) {
        $payload = quotationPayload([$field => '{malformed-json']);

        $response = $this->withHeader('Accept', 'application/json')->post('/api/v2/quotations', $payload);
        $response->assertStatus(422)->assertJsonValidationErrors([$field]);
    }
});

it('computes prices correctly including sample breakdown in totals', function () {
    Storage::fake('public');
    Mail::fake();
    actingAshUser();

    $payload = quotationPayload([
        'items_json' => json_encode([
            ['id' => 1, 'size_id' => 1, 'size' => 'S', 'quantity' => 2, 'unit_price' => 0],
            ['id' => 2, 'size_id' => 2, 'size' => 'M', 'quantity' => 1, 'unit_price' => 30],
        ]),
        'addons_json' => json_encode([
            ['name' => 'Tag', 'price' => 10],
            ['name' => 'Bag', 'price' => 5],
        ]),
        'breakdown_json' => json_encode([
            'items' => [['size' => 'S', 'qty' => 2], ['size' => 'M', 'qty' => 1]],
            'sample_breakdown' => [
                'sample_apparel' => 'Sample Tee',
                'unit_price' => 7,
                'quantity' => 3,
            ],
        ]),
        'discount_type' => 'percentage',
        'discount_price' => 10,
    ]);

    $response = $this->withHeader('Accept', 'application/json')->post('/api/v2/quotations', $payload);
    $response->assertStatus(201);

    // base 100 + neckline 20
    // row1: (120 + 0) * 2 = 240
    // row2: (120 + 30) * 1 = 150
    // items_total = 390
    // addons_total = 15
    // sample_total = 7 * 3 = 21 (recomputed)
    // print_parts_total = 30 per piece across 3 total quantity = 90
    // subtotal = 516
    // discount(10%) = 51.60
    // grand_total = 464.40
    $response->assertJsonPath('data.breakdown.print_parts_total', 90);
    $response->assertJsonPath('data.breakdown.print_parts_unit_total', 30);
    $response->assertJsonPath('data.subtotal', 516);
    $response->assertJsonPath('data.discount_amount', 51.6);
    $response->assertJsonPath('data.grand_total', 464.4);
    $response->assertJsonPath('data.sample_breakdown.price_per_piece', 21);
});
