<?php

/**
 * Phase 5-H — Graphic Artist Portal tests.
 *
 * Run with:
 *   php artisan test --filter=GraphicArtistPortalTest
 *
 * Coverage:
 *   1. buildContext returns full payload for active graphic_artwork stage
 *   2. buildContext rejects stages outside graphic_artwork scope
 *   3. buildContext rejects unknown stage id
 *   4. uploading a design file bumps version and flips is_latest
 *   5. deleting the latest design file promotes the previous version
 *   6. label asset upsert respects (order_id, kind) uniqueness
 *   7. design file create rejects user without action.upload-photos
 *   8. HTTP-level: GET /portal/graphic-artist/context/{id} returns 200
 *      (catches routing/middleware bugs per BUG-010 lesson)
 */

use App\Models\OrderStage;
use App\Models\OrderDesignFile;
use App\Models\OrderLabelAsset;
use App\Models\StageAuditLog;
use App\Services\GraphicArtistPortalService;
use App\Services\OrderDesignFileService;
use App\Services\OrderLabelAssetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // ── Drop everything we touch (reverse FK order) ──────────────
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
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    // ── Domain tables ────────────────────────────────────────────
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('username')->nullable()->unique();
        $t->string('email')->unique();
        $t->string('password')->default('x');
        // Required by FrontendAccess middleware (cast to array via User model).
        $t->text('domain_role')->nullable();
        $t->text('domain_access')->nullable();
        $t->timestamps();
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('shirt_color', 64)->nullable();
        $t->string('special_print', 64)->nullable();
        $t->string('print_area', 64)->nullable();
        $t->text('items_json')->nullable();
        $t->text('notes')->nullable();
        $t->string('workflow_status', 32)->default('inquiry');
        $t->timestamps();
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

    Schema::create('order_design_placements', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_design_id');
        $t->string('type');
        $t->text('mockup_image')->nullable();
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

    // ── All 5 Spatie tables (BUG-004 lesson) ────────────────────
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

    // ── Seed permissions used in tests ──────────────────────────
    $perms = [
        'portal.graphic-artist',
        'action.upload-photos',
        'stage_inputs.delete',
    ];
    foreach ($perms as $name) {
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
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Fixture builders ────────────────────────────────────────────

function gaMakeUser(array $permissionNames = ['portal.graphic-artist', 'action.upload-photos']): \App\Models\User
{
    $user = \App\Models\User::create([
        'name'          => 'Artist ' . uniqid(),
        'username'      => 'artist_' . uniqid(),
        'email'         => 'artist_' . uniqid() . '@test.local',
        // Required by FrontendAccess middleware ('frontend.access:ash')
        // applied at the outer route group. Without 'ash' in this array,
        // every HTTP request returns 403 before the permission check runs.
        'domain_access' => ['ash'],
        'domain_role'   => ['graphic_artist'],
    ]);

    // Use Spatie's own API rather than raw DB inserts. Raw inserts work
    // for in-process $user->can() checks (tests 4 & 7) but the
    // 'permission:' route middleware does NOT see them — it uses Spatie's
    // PermissionRegistrar cache, which only invalidates correctly when
    // grants happen through Permission::firstOrCreate + givePermissionTo.
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

function gaMakeOrderWithStage(string $stageSlug = 'graphic_artwork', string $status = 'in_progress'): array
{
    $order = \App\Models\Order::create([
        'po_code'         => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_name'     => 'ACME Co',
        'client_brand'    => 'Sorbetes',
        'shirt_color'     => 'Black',
        'workflow_status' => 'in_progress',
    ]);
    $stage = OrderStage::create([
        'order_id'     => $order->id,
        'stage'        => $stageSlug,
        'sequence'     => 5,
        'status'       => $status,
        'service_type' => 'in_house',
    ]);
    return [$order, $stage];
}

// ── Tests ───────────────────────────────────────────────────────

test('buildContext returns full payload for active graphic_artwork stage', function () {
    [$order, $stage] = gaMakeOrderWithStage();

    $svc = app(GraphicArtistPortalService::class);
    $ctx = $svc->buildContext($stage->id);

    expect($ctx)->toHaveKeys([
        'order', 'stage', 'design', 'design_files',
        'placements', 'pantones_used',
        'placement_options', 'measurement_options',
        'label_assets', 'screen_details',
        'sample_uploads', 'material_requests', 'activity_log',
    ]);
    expect($ctx['order']['id'])->toBe($order->id);
    expect($ctx['stage']['stage'])->toBe('graphic_artwork');
    expect($ctx['label_assets'])->toHaveKeys(['main_label', 'size_label', 'hangtag']);
    expect($ctx['label_assets']['main_label'])->toBeNull();
    expect($ctx['design_files'])->toBe([]);
});

test('buildContext rejects stages outside graphic_artwork scope', function () {
    [, $stage] = gaMakeOrderWithStage('mass_production');

    $svc = app(GraphicArtistPortalService::class);
    $svc->buildContext($stage->id);
})->throws(\Illuminate\Validation\ValidationException::class);

test('buildContext rejects unknown stage id', function () {
    $svc = app(GraphicArtistPortalService::class);
    $svc->buildContext(999999);
})->throws(\Illuminate\Validation\ValidationException::class);

test('uploading a design file bumps version and flips is_latest', function () {
    [$order, $stage] = gaMakeOrderWithStage();
    $user = gaMakeUser();

    $svc = app(OrderDesignFileService::class);

    $v1 = $svc->create([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'kind'           => 'front_design',
        'file_path'      => 'graphic-artist/designs/1/v1.png',
        'original_name'  => 'logo.png',
        'mime_type'      => 'image/png',
        'size_bytes'     => 1024,
    ], $user);

    expect($v1->version)->toBe(1);
    expect($v1->is_latest)->toBeTrue();

    $v2 = $svc->create([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'kind'           => 'front_design',
        'file_path'      => 'graphic-artist/designs/1/v2.png',
        'original_name'  => 'logo-v2.png',
        'mime_type'      => 'image/png',
        'size_bytes'     => 2048,
    ], $user);

    expect($v2->version)->toBe(2);
    expect($v2->is_latest)->toBeTrue();

    // v1 must have been demoted.
    expect(OrderDesignFile::find($v1->id)->is_latest)->toBeFalse();

    // Audit log written for each upload.
    $audits = StageAuditLog::where('action', OrderDesignFileService::AUDIT_UPLOADED)
        ->where('order_stage_id', $stage->id)
        ->get();
    expect($audits)->toHaveCount(2);
});

test('deleting the latest design file promotes the previous version', function () {
    [$order, $stage] = gaMakeOrderWithStage();
    $user = gaMakeUser();

    $svc = app(OrderDesignFileService::class);

    $v1 = $svc->create([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'kind'           => 'back_mockup',
        'file_path'      => 'graphic-artist/designs/1/back-v1.png',
        'original_name'  => 'back-v1.png',
        'mime_type'      => 'image/png',
        'size_bytes'     => 1024,
    ], $user);
    $v2 = $svc->create([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'kind'           => 'back_mockup',
        'file_path'      => 'graphic-artist/designs/1/back-v2.png',
        'original_name'  => 'back-v2.png',
        'mime_type'      => 'image/png',
        'size_bytes'     => 2048,
    ], $user);

    // Delete the latest (v2). v1 should be promoted to is_latest=true.
    $svc->delete($v2->id, $stage->id, $user);

    expect(OrderDesignFile::find($v2->id))->toBeNull();
    expect(OrderDesignFile::find($v1->id)->is_latest)->toBeTrue();

    // Audit log captured the delete.
    $audit = StageAuditLog::where('action', OrderDesignFileService::AUDIT_DELETED)
        ->where('order_stage_id', $stage->id)
        ->first();
    expect($audit)->not->toBeNull();
});

test('label asset upsert respects (order_id, kind) uniqueness', function () {
    [$order, $stage] = gaMakeOrderWithStage();
    $user = gaMakeUser();

    $svc = app(OrderLabelAssetService::class);

    $first = $svc->upsert([
        'order_id'         => $order->id,
        'order_stage_id'   => $stage->id,
        'kind'             => 'main_label',
        'width_in'         => 2.5,
        'height_in'        => 1.5,
        'printing_process' => 'silkscreen',
        'color_count'      => 1,
        'background_color' => 'Black',
    ], $user);

    expect($first->id)->toBeGreaterThan(0);
    expect((float) $first->width_in)->toBe(2.5);

    // Upserting same kind for same order should UPDATE, not duplicate.
    $second = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'kind'           => 'main_label',
        'width_in'       => 3.0,
        'notes'          => 'Updated dimensions',
    ], $user);

    expect($second->id)->toBe($first->id);
    expect((float) $second->width_in)->toBe(3.0);
    // Other fields preserved (not nuked by partial upsert).
    expect($second->printing_process)->toBe('silkscreen');

    expect(OrderLabelAsset::where('order_id', $order->id)->count())->toBe(1);

    // Different kind = different row.
    $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'kind'           => 'hangtag',
        'material'       => '300gsm matte',
    ], $user);
    expect(OrderLabelAsset::where('order_id', $order->id)->count())->toBe(2);
});

test('design file create rejects user without action.upload-photos', function () {
    [$order, $stage] = gaMakeOrderWithStage();
    // User has portal access but not the upload-photos action permission.
    $user = gaMakeUser(['portal.graphic-artist']);

    $svc = app(OrderDesignFileService::class);

    $svc->create([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'kind'           => 'front_design',
        'file_path'      => 'graphic-artist/designs/1/x.png',
        'original_name'  => 'x.png',
        'mime_type'      => 'image/png',
        'size_bytes'     => 1,
    ], $user);
})->throws(\Illuminate\Validation\ValidationException::class);

test('HTTP: GET /portal/graphic-artist/context/{id} returns 200 with auth + permission', function () {
    [, $stage] = gaMakeOrderWithStage();
    $user = gaMakeUser(['portal.graphic-artist']);

    // Authenticate via Sanctum-aware actingAs
    $this->actingAs($user, 'sanctum');

    $response = $this->getJson("/api/v2/portal/graphic-artist/context/{$stage->id}");

    // Catches any wildcard collision, middleware misconfiguration, or
    // controller wiring problem (BUG-010 methodology).
    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            'order', 'stage', 'design_files', 'label_assets',
            'placement_options', 'measurement_options',
        ],
    ]);
});