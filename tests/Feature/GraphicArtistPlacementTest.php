<?php

/**
 * GA Portal CP1 — GA-writable print placements tests.
 *
 * Run with:
 *   php artisan test --filter=GraphicArtistPlacementTest
 *
 * Coverage:
 *   1.  upsert creates the design row + placement, normalising pantones
 *   2.  duplicate placement type (case-insensitive) is rejected
 *   3.  update by id changes pantones / color_count; null pantones keeps
 *   4.  delete removes the row and audit-logs
 *   5.  write against a completed stage is rejected
 *   6.  actor without action.upload-photos is rejected
 *   7.  context: suggested_placements seeded from order print_parts_json
 *   8.  context: suggested_placements falls back to quotation parts
 *   9.  context: suggestions disappear once a real placement exists
 *   10. context: completion_warnings — missing files/placements/pantones
 *   11. HTTP: POST + _method=PUT multipart upsert with artwork file
 *   12. HTTP: DELETE placement route works end-to-end
 *
 * Helper names are prefixed gapx* — Pest loads all test files into one
 * process, so these must not collide with gaMakeUser/gaMakeOrderWithStage
 * in GraphicArtistPortalTest.php.
 */

use App\Models\OrderDesign;
use App\Models\OrderDesignPlacement;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Services\GraphicArtistPortalService;
use App\Services\OrderDesignPlacementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',

        'stage_audit_logs',
        'stage_sample_uploads',
        'material_requests',
        'screen_assignments',
        'screens',
        'order_label_assets',
        'order_design_files',
        'order_design_placements',
        'order_designs',
        'placement_measurements',
        'print_label_placements',
        'pantones',
        'stage_uploads',
        'quotations',
        'order_role_notes',
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('username')->nullable()->unique();
        $t->string('email')->unique();
        $t->string('password')->default('x');
        $t->text('domain_role')->nullable();
        $t->text('domain_access')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });

    // CP1 — GraphicArtistPortalService::buildContext and the hub payload
    // now ride the role-directed instruction threads (order_role_notes),
    // so the hand-built schema needs the table.
    Schema::create('order_role_notes', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('audience_role', 64);
        $t->unsignedBigInteger('author_user_id');
        $t->text('body');
        $t->timestamps();
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('quotation_id')->nullable();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('shirt_color', 64)->nullable();
        $t->string('special_print', 64)->nullable();
        $t->string('print_area', 64)->nullable();
        $t->json('print_parts_json')->nullable();
        $t->text('items_json')->nullable();
        $t->text('notes')->nullable();
        $t->string('workflow_status', 32)->default('inquiry');
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->text('stage');
        $t->unsignedSmallInteger('sequence')->default(0);
        $t->string('status')->default('pending');
        $t->string('service_type', 16)->default('in_house');
        $t->timestamp('started_at')->nullable();
        $t->timestamp('completed_at')->nullable();
        $t->timestamp('delayed_at')->nullable();
        $t->unsignedBigInteger('assigned_to')->nullable();
        $t->string('assigned_role', 64)->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('pantones', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('hexcolor');
        $t->string('pantone_code');
        $t->timestamps();
    });

    Schema::create('print_label_placements', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->text('description')->nullable();
        $t->timestamps();
    });

    Schema::create('placement_measurements', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->text('description')->nullable();
        $t->timestamps();
    });

    Schema::create('order_designs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('artist_id')->nullable();
        $t->text('notes')->nullable();
        $t->text('size_label')->nullable();
        $t->timestamps();
    });

    // CP1 — includes the new color_count column.
    Schema::create('order_design_placements', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_design_id');
        $t->string('type');
        $t->text('mockup_image')->nullable();
        $t->unsignedTinyInteger('color_count')->nullable();
        $t->text('pantones')->nullable();
        $t->timestamps();
    });

    Schema::create('order_design_files', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_design_id')->nullable();
        $t->string('kind', 32);
        $t->unsignedInteger('version')->default(1);
        $t->string('file_path', 255);
        $t->string('original_name', 255);
        $t->string('mime_type', 64);
        $t->unsignedBigInteger('size_bytes');
        $t->boolean('is_latest')->default(true);
        $t->unsignedBigInteger('uploaded_by_user_id');
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('order_label_assets', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('kind', 32);
        $t->string('file_path', 255)->nullable();
        $t->string('original_name', 255)->nullable();
        $t->string('mime_type', 64)->nullable();
        $t->unsignedBigInteger('size_bytes')->nullable();
        $t->decimal('width_in', 6, 2)->nullable();
        $t->decimal('height_in', 6, 2)->nullable();
        $t->string('printing_process', 32)->nullable();
        $t->unsignedTinyInteger('color_count')->nullable();
        $t->string('background_color', 32)->nullable();
        $t->string('material', 64)->nullable();
        $t->text('notes')->nullable();
        $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
        $t->timestamps();
        $t->unique(['order_id', 'kind']);
    });

    Schema::create('screens', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->string('mesh_count')->nullable();
        $t->string('address')->nullable();
        $t->string('size')->nullable();
        $t->timestamps();
    });

    Schema::create('screen_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('placement_id');
        $t->unsignedBigInteger('screen_id');
        $t->integer('color_index');
        $t->timestamps();
    });

    Schema::create('material_requests', function (Blueprint $t) {
        $t->id();
        $t->string('mr_code')->unique();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('stage_id')->nullable();
        $t->unsignedBigInteger('requested_by_user_id');
        $t->string('status', 16)->default('pending');
        $t->text('reason')->nullable();
        $t->text('rejection_reason')->nullable();
        $t->unsignedBigInteger('approved_by_user_id')->nullable();
        $t->timestamp('approved_at')->nullable();
        $t->unsignedBigInteger('purchase_request_id')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_sample_uploads', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('uploaded_by_user_id');
        $t->string('photo_front_path')->nullable();
        $t->string('photo_back_path')->nullable();
        $t->text('remarks')->nullable();
        $t->string('sample_status', 16)->default('for_approval');
        $t->timestamp('completed_at')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_audit_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('action', 32);
        $t->string('from_status', 32)->nullable();
        $t->string('to_status', 32)->nullable();
        $t->unsignedBigInteger('duration_seconds')->nullable();
        $t->unsignedBigInteger('business_duration_seconds')->nullable();
        $t->text('notes')->nullable();
        $t->timestamp('created_at')->nullable();
    });

    Schema::create('stage_uploads', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id')->nullable();
        $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
        $t->string('category', 64)->nullable();
        $t->string('file_path')->nullable();
        $t->string('original_name')->nullable();
        $t->string('mime_type', 128)->nullable();
        $t->unsignedBigInteger('size_bytes')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('quotations', function (Blueprint $t) {
        $t->id();
        $t->json('print_parts_json')->nullable();
        $t->string('custom_pattern_image')->nullable();
        $t->string('label_design_path')->nullable();
        $t->string('design_review_status')->nullable();
        $t->timestamps();
    });

    Schema::create('permissions', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name');
        $t->timestamps();
    });
    Schema::create('roles', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name');
        $t->timestamps();
    });
    Schema::create('model_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['permission_id', 'model_id', 'model_type']);
    });
    Schema::create('model_has_roles', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });
    Schema::create('role_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->unsignedBigInteger('role_id');
        $t->primary(['permission_id', 'role_id']);
    });

    foreach ([
        'portal.graphic-artist',
        'action.upload-photos',
        'stage_inputs.delete',
    ] as $name) {
        DB::table('permissions')->insert([
            'name'       => $name,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',
        'stage_audit_logs',
        'stage_sample_uploads',
        'material_requests',
        'screen_assignments',
        'screens',
        'order_label_assets',
        'order_design_files',
        'order_design_placements',
        'order_designs',
        'placement_measurements',
        'print_label_placements',
        'pantones',
        'stage_uploads',
        'quotations',
        'order_role_notes',
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Fixture builders (gapx* — must not collide with other test files) ──

function gapxMakeUser(array $permissionNames = ['portal.graphic-artist', 'action.upload-photos']): \App\Models\User
{
    $user = \App\Models\User::create([
        'name'          => 'Artist ' . uniqid(),
        'username'      => 'artist_' . uniqid(),
        'email'         => 'artist_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['graphic_artist'],
    ]);

    foreach ($permissionNames as $pname) {
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name'       => $pname,
            'guard_name' => 'web',
        ]);
    }

    if ($permissionNames !== []) {
        $user->givePermissionTo($permissionNames);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    return $user;
}

function gapxMakeOrderWithStage(string $status = 'in_progress', array $orderAttrs = []): array
{
    $order = \App\Models\Order::create(array_merge([
        'po_code'         => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_name'     => 'ACME Co',
        'client_brand'    => 'Sorbetes',
        'shirt_color'     => 'Black',
        'workflow_status' => 'in_progress',
    ], $orderAttrs));
    $stage = OrderStage::create([
        'order_id'     => $order->id,
        'stage'        => 'graphic_artwork',
        'sequence'     => 5,
        'status'       => $status,
        'service_type' => 'in_house',
    ]);
    return [$order, $stage];
}

// ── Service tests ───────────────────────────────────────────────

test('upsert creates design row + placement with normalized pantones', function () {
    [$order, $stage] = gapxMakeOrderWithStage();
    $user = gapxMakeUser();

    $pantone = \App\Models\Pantone::create([
        'name' => 'Fire Red', 'hexcolor' => '#C8102E', 'pantone_code' => 'PMS 186 C',
    ]);

    $svc = app(OrderDesignPlacementService::class);
    $placement = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
        'color_count'    => 5,
        'pantones'       => [
            $pantone->id,                     // ID reference
            'PMS 3005 C',                     // plain code string
            ['pantone_code' => 'PMS 109 C'],  // inline descriptor
            '   ',                            // blank — dropped
        ],
    ], $user);

    expect($placement->type)->toBe('Body Front');
    expect((int) $placement->color_count)->toBe(5);
    expect($placement->pantones)->toHaveCount(3);
    expect($placement->pantones[0])->toBe($pantone->id);
    expect($placement->pantones[1]['pantone_code'])->toBe('PMS 3005 C');

    $design = OrderDesign::where('order_id', $order->id)->first();
    expect($design)->not->toBeNull();
    expect((int) $design->artist_id)->toBe($user->id);
    expect((int) $placement->order_design_id)->toBe($design->id);

    expect(StageAuditLog::where('action', OrderDesignPlacementService::AUDIT_UPSERTED)->count())->toBe(1);
});

test('duplicate placement type is rejected case-insensitively', function () {
    [$order, $stage] = gapxMakeOrderWithStage();
    $user = gapxMakeUser();
    $svc  = app(OrderDesignPlacementService::class);

    $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
    ], $user);

    $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'body front',
    ], $user);
})->throws(\Illuminate\Validation\ValidationException::class);

test('update by id changes color_count and pantones; null pantones keeps existing', function () {
    [$order, $stage] = gapxMakeOrderWithStage();
    $user = gapxMakeUser();
    $svc  = app(OrderDesignPlacementService::class);

    $created = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
        'color_count'    => 3,
        'pantones'       => ['PMS 186 C'],
    ], $user);

    // Pass pantones = null → existing kept; bump color_count.
    $updated = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'id'             => $created->id,
        'type'           => 'Body Front',
        'color_count'    => 4,
        'pantones'       => null,
    ], $user);

    expect((int) $updated->color_count)->toBe(4);
    expect($updated->pantones)->toHaveCount(1);

    // Explicit [] clears.
    $cleared = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'id'             => $created->id,
        'type'           => 'Body Front',
        'pantones'       => [],
    ], $user);

    expect($cleared->pantones)->toBe([]);
    expect(OrderDesignPlacement::count())->toBe(1);
});

test('delete removes placement and audit-logs', function () {
    [$order, $stage] = gapxMakeOrderWithStage();
    $user = gapxMakeUser();
    $svc  = app(OrderDesignPlacementService::class);

    $placement = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Left Sleeve',
    ], $user);

    $svc->delete($placement->id, $stage->id, $user);

    expect(OrderDesignPlacement::find($placement->id))->toBeNull();
    expect(StageAuditLog::where('action', OrderDesignPlacementService::AUDIT_DELETED)->count())->toBe(1);
});

test('write against a completed stage is rejected', function () {
    [$order, $stage] = gapxMakeOrderWithStage('completed');
    $user = gapxMakeUser();

    app(OrderDesignPlacementService::class)->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
    ], $user);
})->throws(\Illuminate\Validation\ValidationException::class);

test('actor without action.upload-photos is rejected', function () {
    [$order, $stage] = gapxMakeOrderWithStage();
    $user = gapxMakeUser(['portal.graphic-artist']); // no upload perm

    app(OrderDesignPlacementService::class)->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
    ], $user);
})->throws(\Illuminate\Validation\ValidationException::class);

// ── Context: suggestions + warnings ─────────────────────────────

test('context suggests placements from order print_parts_json when none saved', function () {
    [$order, $stage] = gapxMakeOrderWithStage('in_progress', [
        'print_parts_json' => [
            ['part' => 'Body Front', 'image' => 'orders/front-art.png', 'color_count' => 3],
            ['part' => 'Back',       'image_input_type' => 'link', 'image_link' => 'https://canva.com/x', 'color_count' => 0, 'full_color_count' => 4],
        ],
    ]);

    $ctx = app(GraphicArtistPortalService::class)->buildContext($stage->id);

    expect($ctx)->toHaveKey('pantone_options');
    expect($ctx['order'])->toHaveKeys(['design_name', 'fabric_type', 'brand_label', 'shirt_color_hex']);
    expect($ctx['suggested_placements'])->toHaveCount(2);
    expect($ctx['suggested_placements'][0]['type'])->toBe('Body Front');
    expect($ctx['suggested_placements'][0]['color_count'])->toBe(3);
    expect($ctx['suggested_placements'][0]['source'])->toBe('quotation');
    expect($ctx['suggested_placements'][1]['is_link'])->toBeTrue();
    expect($ctx['suggested_placements'][1]['color_count'])->toBe(4); // full_color_count fallback
});

test('context suggestions fall back to the source quotation parts', function () {
    $quotation = \App\Models\Quotation::create([
        'print_parts_json' => [
            ['part' => 'Sleeve', 'image' => 'q/sleeve.png', 'color_count' => 2],
        ],
    ]);
    [$order, $stage] = gapxMakeOrderWithStage('in_progress', [
        'quotation_id'     => $quotation->id,
        'print_parts_json' => null,
    ]);

    $ctx = app(GraphicArtistPortalService::class)->buildContext($stage->id);

    expect($ctx['suggested_placements'])->toHaveCount(1);
    expect($ctx['suggested_placements'][0]['type'])->toBe('Sleeve');
    expect($ctx['suggested_placements'][0]['color_count'])->toBe(2);
});

test('suggestions disappear once a real placement exists', function () {
    [$order, $stage] = gapxMakeOrderWithStage('in_progress', [
        'print_parts_json' => [
            ['part' => 'Body Front', 'image' => 'orders/front-art.png', 'color_count' => 3],
        ],
    ]);
    $user = gapxMakeUser();

    app(OrderDesignPlacementService::class)->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
        'color_count'    => 3,
    ], $user);

    $ctx = app(GraphicArtistPortalService::class)->buildContext($stage->id);
    expect($ctx['suggested_placements'])->toBe([]);
    expect($ctx['placements'])->toHaveCount(1);
    expect($ctx['placements'][0]['color_count'])->toBe(3);
});

test('completion_warnings reports missing artwork and pantone shortfalls', function () {
    [$order, $stage] = gapxMakeOrderWithStage();
    $user = gapxMakeUser();
    $svc  = app(OrderDesignPlacementService::class);

    // Nothing at all → no placements. (CP5: the no_design_files warning
    // was removed together with the portal's Design Files section.)
    $ctx = app(GraphicArtistPortalService::class)->buildContext($stage->id);
    $codes = array_column($ctx['completion_warnings'], 'code');
    expect($codes)->not->toContain('no_design_files');
    expect($codes)->toContain('no_placements');

    // Placement with 1/3 pantones, no artwork.
    $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
        'color_count'    => 3,
        'pantones'       => ['PMS 186 C'],
    ], $user);

    $ctx = app(GraphicArtistPortalService::class)->buildContext($stage->id);
    $codes = array_column($ctx['completion_warnings'], 'code');
    expect($codes)->toContain('placement_no_artwork');
    expect($codes)->toContain('placement_pantones_incomplete');
    expect($codes)->not->toContain('no_placements');

    $messages = implode(' | ', array_column($ctx['completion_warnings'], 'message'));
    expect($messages)->toContain('(1/3)');
});

// ── HTTP-level ──────────────────────────────────────────────────

test('HTTP: multipart POST + _method=PUT upserts a placement with artwork', function () {
    Storage::fake('public');
    [$order, $stage] = gapxMakeOrderWithStage();
    $user = gapxMakeUser();

    $this->actingAs($user, 'sanctum');

    $response = $this->post('/api/v2/portal/graphic-artist/placements', [
        '_method'        => 'PUT',
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
        'color_count'    => 5,
        'pantones'       => json_encode(['PMS 186 C', 'PMS 3005 C']),
        'artwork'        => UploadedFile::fake()->image('front.png', 400, 400),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(200);
    $data = $response->json('data');
    expect($data['type'])->toBe('Body Front');
    expect($data['color_count'])->toBe(5);
    expect($data['pantones'])->toHaveCount(2);
    expect($data['mockup_url'])->not->toBeNull();

    $row = OrderDesignPlacement::first();
    Storage::disk('public')->assertExists($row->mockup_image);
});

test('HTTP: DELETE placement route works end-to-end', function () {
    [$order, $stage] = gapxMakeOrderWithStage();
    $user = gapxMakeUser();
    $placement = app(OrderDesignPlacementService::class)->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Back',
    ], $user);

    $this->actingAs($user, 'sanctum');

    $response = $this->deleteJson(
        "/api/v2/portal/graphic-artist/placements/{$placement->id}",
        ['order_stage_id' => $stage->id],
    );

    $response->assertStatus(200);
    expect(OrderDesignPlacement::find($placement->id))->toBeNull();
});
