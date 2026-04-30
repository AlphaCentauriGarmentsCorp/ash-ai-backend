<?php

use App\Models\ApparelPatternPrice;
use App\Models\Quotation;
use App\Models\QuotationShareToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function createSharedQuotation(string $permission = 'edit', array $tokenOverrides = []): array
{
    $suffix = uniqid();

    $user = User::factory()->create([
        'username' => 'ash_' . $suffix,
        'domain_role' => ['admin'],
        'domain_access' => ['ash'],
    ]);

    $pattern = ApparelPatternPrice::create([
        'apparel_type_id' => 1,
        'pattern_type_id' => 1,
        'apparel_type_name' => 'tee-' . $suffix,
        'pattern_type_name' => 'std-' . $suffix,
        'price' => 100,
    ]);

    $quotation = Quotation::create([
        'quotation_id' => 'QUO-2026-' . random_int(100000, 999999),
        'user_id' => $user->id,
        'client_name' => 'Public Client',
        'client_email' => null,
        'client_brand' => 'Brand X',
        'shirt_color' => 'Black',
        'free_items' => null,
        'notes' => null,
        'discount_type' => null,
        'discount_price' => 0,
        'discount_amount' => 0,
        'item_config_json' => [
            'apparel_pattern_price_id' => $pattern->id,
        ],
        'items_json' => [
            ['id' => 1, 'size_id' => 1, 'size' => 'M', 'quantity' => 2, 'unit_price' => 5],
        ],
        'addons_json' => [],
        'breakdown_json' => [
            'items' => [],
            'sample_breakdown' => [
                'sample_apparel' => null,
                'unit_price' => 0,
                'quantity' => 0,
                'price_per_piece' => 0,
            ],
            'print_parts_total' => 10,
            'print_parts_unit_total' => 5,
        ],
        'print_parts_json' => [
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 1,
                'price_per_unit' => 5,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'print_part_total' => 5,
                'image_input_type' => 'link',
                'image_link' => 'https://example.com/front-old.png',
                'image' => null,
            ],
        ],
        'subtotal' => 220,
        'grand_total' => 220,
        'status' => 'Pending',
    ]);

    $token = QuotationShareToken::create(array_merge([
        'quotation_id' => $quotation->id,
        'created_by' => $user->id,
        'token' => bin2hex(random_bytes(32)),
        'permission' => $permission,
        'allow_download' => false,
        'is_revoked' => false,
        'expires_at' => now()->addDay(),
        'label' => 'public update link',
    ], $tokenOverrides));

    return [$quotation, $token];
}

it('updates public quotation print parts via json payload and persists recalculated totals', function () {
    Storage::fake('public');
    Mail::fake();

    [$quotation, $token] = createSharedQuotation('edit');

    $payload = [
        'print_parts_json' => json_encode([
            [
                'part_id' => 2,
                'part' => 'Back',
                'unit_count' => 2,
                'price_per_unit' => 10,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'link',
                'image_link' => 'https://example.com/back-new.png',
            ],
        ]),
    ];

    $update = $this->withHeader('Accept', 'application/json')
        ->put('/api/v2/share/quotations/' . $token->token, $payload);

    $update->assertOk()
        ->assertJsonPath('data.print_parts.0.part', 'Back')
        ->assertJsonPath('data.print_parts.0.part_id', 2)
        ->assertJsonPath('data.print_parts.0.print_part_total', 20);

    $quotation->refresh();

    expect($quotation->print_parts_json[0]['part'])->toBe('Back');
    expect($quotation->print_parts_json[0]['part_id'])->toBe(2);
    expect($quotation->print_parts_json[0]['image_link'])->toBe('https://example.com/back-new.png');
    expect((float) $quotation->subtotal)->toBe(220.0);
    expect((float) $quotation->grand_total)->toBe(220.0);
    expect((float) ($quotation->breakdown_json['print_parts_total'] ?? 0))->toBe(40.0);
    expect((float) ($quotation->breakdown_json['print_parts_unit_total'] ?? 0))->toBe(20.0);

    $fetch = $this->withHeader('Accept', 'application/json')
        ->get('/api/v2/share/quotations/' . $token->token);

    $fetch->assertOk()
        ->assertJsonPath('data.print_parts.0.part', 'Back')
        ->assertJsonPath('data.print_parts.0.print_part_total', 20);
});

it('updates public quotation print parts with file uploads', function () {
    Storage::fake('public');
    Mail::fake();

    [$quotation, $token] = createSharedQuotation('edit');

    $payload = [
        'print_parts_json' => json_encode([
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 3,
                'price_per_unit' => 5,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'file',
                'image_link' => '',
            ],
        ]),
        'print_parts_files' => [
            UploadedFile::fake()->create('front-new.png', 64, 'image/png'),
        ],
    ];

    $response = $this->withHeader('Accept', 'application/json')
        ->put('/api/v2/share/quotations/' . $token->token, $payload);

    $response->assertOk()
        ->assertJsonPath('data.print_parts.0.image_input_type', 'file')
        ->assertJsonPath('data.print_parts.0.print_part_total', 15);

    $quotation->refresh();

    expect($quotation->print_parts_json[0]['image'])->not->toBeNull();
    expect($quotation->print_parts_json[0]['image_link'])->toBeNull();
    expect(str_starts_with($quotation->print_parts_json[0]['image'], 'quotation-print-parts/'))->toBeTrue();
    Storage::disk('public')->assertExists($quotation->print_parts_json[0]['image']);
});

it('prefers indexed multipart print part data and persists updated color count', function () {
    Storage::fake('public');
    Mail::fake();

    [$quotation, $token] = createSharedQuotation('edit');

    $payload = [
        'print_parts_json' => [
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 2,
                'price_per_unit' => 0,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'file',
                'image_link' => 'quotation-print-parts/existing-front.png',
            ],
        ],
    ];

    $update = $this->withHeader('Accept', 'application/json')
        ->put('/api/v2/share/quotations/' . $token->token, $payload);

    $update->assertOk()
        ->assertJsonPath('data.print_parts.0.unit_count', 2)
        ->assertJsonPath('data.print_parts.0.price_per_unit', 0)
        ->assertJsonPath('data.print_parts.0.image_input_type', 'file');

    $quotation->refresh();

    expect((float) ($quotation->print_parts_json[0]['unit_count'] ?? 0))->toBe(2.0);
    expect((float) ($quotation->print_parts_json[0]['price_per_unit'] ?? 0))->toBe(0.0);

    $fetch = $this->withHeader('Accept', 'application/json')
        ->get('/api/v2/share/quotations/' . $token->token);

    $fetch->assertOk()
        ->assertJsonPath('data.print_parts.0.unit_count', 2)
        ->assertJsonPath('data.print_parts.0.price_per_unit', 0);
});

it('persists mixed multipart print parts payload from frontend shape', function () {
    Storage::fake('public');
    Mail::fake();

    [$quotation, $token] = createSharedQuotation('edit');

    $payload = [
        'print_parts_json' => [
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 2,
                'price_per_unit' => 25,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'file',
                'image_link' => '',
            ],
            [
                'part_id' => 2,
                'part' => 'Back',
                'unit_count' => 3,
                'price_per_unit' => 20,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'link',
                'image_link' => 'https://example.com/back-artwork.png',
            ],
        ],
    ];

    $update = $this->withHeader('Accept', 'application/json')
        ->put('/api/v2/share/quotations/' . $token->token, $payload);

    $update->assertOk()
        ->assertJsonPath('data.print_parts.0.part_id', 1)
        ->assertJsonPath('data.print_parts.0.unit_count', 2)
        ->assertJsonPath('data.print_parts.0.price_per_unit', 25)
        ->assertJsonPath('data.print_parts.1.part_id', 2)
        ->assertJsonPath('data.print_parts.1.unit_count', 3)
        ->assertJsonPath('data.print_parts.1.price_per_unit', 20)
        ->assertJsonPath('data.print_parts.1.image_input_type', 'link')
        ->assertJsonPath('data.print_parts.1.image_link', 'https://example.com/back-artwork.png');

    $quotation->refresh();

    expect((float) ($quotation->print_parts_json[0]['unit_count'] ?? 0))->toBe(2.0);
    expect((float) ($quotation->print_parts_json[0]['price_per_unit'] ?? 0))->toBe(25.0);
    expect((float) ($quotation->print_parts_json[1]['unit_count'] ?? 0))->toBe(3.0);
    expect((float) ($quotation->print_parts_json[1]['price_per_unit'] ?? 0))->toBe(20.0);
    expect($quotation->print_parts_json[1]['image_link'])->toBe('https://example.com/back-artwork.png');
});

it('accepts public update payload using print_parts key', function () {
    Storage::fake('public');
    Mail::fake();

    [$quotation, $token] = createSharedQuotation('edit');

    $payload = [
        'print_parts' => json_encode([
            [
                'part_id' => 2,
                'part' => 'Back',
                'unit_count' => 4,
                'price_per_unit' => 7.5,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'link',
                'image_link' => 'https://example.com/back-print-parts.png',
            ],
        ]),
    ];

    $update = $this->withHeader('Accept', 'application/json')
        ->put('/api/v2/share/quotations/' . $token->token, $payload);

    $update->assertOk()
        ->assertJsonPath('data.print_parts.0.part', 'Back')
        ->assertJsonPath('data.print_parts.0.unit_count', 4)
        ->assertJsonPath('data.print_parts.0.price_per_unit', 7.5)
        ->assertJsonPath('data.print_parts.0.print_part_total', 30);

    $quotation->refresh();

    expect((float) ($quotation->print_parts_json[0]['unit_count'] ?? 0))->toBe(4.0);
    expect((float) ($quotation->print_parts_json[0]['price_per_unit'] ?? 0))->toBe(7.5);
    expect((float) ($quotation->print_parts_json[0]['print_part_total'] ?? 0))->toBe(30.0);
});

it('rejects public update for invalid, revoked, and expired tokens', function () {
    Storage::fake('public');
    Mail::fake();

    [$quotation, $validToken] = createSharedQuotation('edit');

    $payload = [
        'print_parts_json' => json_encode([
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 1,
                'price_per_unit' => 10,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'link',
                'image_link' => 'https://example.com/front.png',
            ],
        ]),
    ];

    $invalid = $this->withHeader('Accept', 'application/json')
        ->put('/api/v2/share/quotations/invalid-token', $payload);
    $invalid->assertStatus(404);

    $validToken->update(['is_revoked' => true]);
    $revoked = $this->withHeader('Accept', 'application/json')
        ->put('/api/v2/share/quotations/' . $validToken->token, $payload);
    $revoked->assertStatus(403);

    [$quotation2, $expiredToken] = createSharedQuotation('edit', [
        'expires_at' => now()->subMinute(),
    ]);

    $expired = $this->withHeader('Accept', 'application/json')
        ->put('/api/v2/share/quotations/' . $expiredToken->token, $payload);
    $expired->assertStatus(403);
});

it('forbids public update when token permission is view', function () {
    Storage::fake('public');
    Mail::fake();

    [$quotation, $token] = createSharedQuotation('view');

    $payload = [
        'print_parts_json' => json_encode([
            [
                'part_id' => 1,
                'part' => 'Front',
                'unit_count' => 2,
                'price_per_unit' => 10,
                'full_unit_count' => 0,
                'price_per_full_unit' => 0,
                'image_input_type' => 'link',
                'image_link' => 'https://example.com/front.png',
            ],
        ]),
    ];

    $response = $this->withHeader('Accept', 'application/json')
        ->put('/api/v2/share/quotations/' . $token->token, $payload);

    $response->assertStatus(403);

    $quotation->refresh();
    expect($quotation->print_parts_json[0]['part'])->toBe('Front');
    expect($quotation->print_parts_json[0]['print_part_total'])->toBe(5);
});
