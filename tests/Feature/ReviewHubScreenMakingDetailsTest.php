<?php

/**
 * SM Rework CP1 — Review Hub Screen Making details tests.
 *
 * Run with:
 *   php artisan test --filter=ReviewHubScreenMakingDetailsTest
 *
 * Coverage:
 *   1. ScreenMakerPortalService::reviewSummary returns the SM output block
 *      (design + placements + labels + physical screens + stage notes)
 *   2. HTTP: GET /orders/{id}/stage-reviews includes stage_details keyed
 *      by the screen_making stage id (BUG-010 lesson: exercises the
 *      controller's new constructor dependency + payload wiring)
 *   3. HTTP: order without a screen_making stage → no screen block
 *
 * Schema mirrors ReviewHubGaDetailsTest (the whole hub read path is
 * exercised end-to-end). Helper names prefixed rhsm* to avoid Pest
 * global-function collisions.
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Services\ScreenMakerPortalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

$RHSM_TABLES = [
    'role_has_permissions',
    'model_has_permissions',
    'model_has_roles',
    'roles',
    'permissions',

    'order_role_notes',
    'stage_reviews',
    'order_payments',
    'payment_methods',
    'qa_packer_task_completions',
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
    'order_stages',
    'orders',
    'users',
];

beforeEach(function () use ($RHSM_TABLES) {
    foreach ($RHSM_TABLES as $t) {
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
        // Label specs read by ScreenMakerPortalService::reviewSummary.
        $t->json('brand_label_json')->nullable();
        $t->json('care_label_json')->nullable();
        $t->string('label_design_path')->nullable();
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

    Schema::create('stage_reviews', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('actor_user_id')->nullable();
        $t->string('decision', 16);
        $t->text('comment')->nullable();
        $t->string('image_path')->nullable();
        $t->timestamps();
    });

    Schema::create('payment_methods', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('type', 32)->nullable();
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });

    Schema::create('order_payments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('payment_type', 16);
        $t->decimal('amount', 10, 2);
        $t->unsignedBigInteger('payment_method_id')->nullable();
        $t->string('reference_number')->nullable();
        $t->string('payer_name')->nullable();
        $t->timestamp('paid_at')->nullable();
        $t->string('proof_path', 255)->nullable();
        $t->string('status', 24)->default('waiting');
        $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
        $t->timestamp('uploaded_at')->nullable();
        $t->unsignedBigInteger('verified_by_user_id')->nullable();
        $t->timestamp('verified_at')->nullable();
        $t->text('rejection_reason')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('qa_packer_task_completions', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('submitted_by_user_id');
        $t->json('checklist_state_json')->nullable();
        $t->json('final_photos_json')->nullable();
        $t->json('reject_summary_json')->nullable();
        $t->text('notes')->nullable();
        $t->timestamp('submitted_at')->nullable();
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

    foreach (['access.orders', 'action.upload-photos', 'portal.screen-maker'] as $name) {
        DB::table('permissions')->insert([
            'name'       => $name,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () use ($RHSM_TABLES) {
    foreach ($RHSM_TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Fixture builders (rhsm*) ────────────────────────────────────

function rhsmMakeUser(array $permissionNames = ['access.orders']): \App\Models\User
{
    $user = \App\Models\User::create([
        'name'          => 'Reviewer ' . uniqid(),
        'username'      => 'reviewer_' . uniqid(),
        'email'         => 'reviewer_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['csr'],
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

/**
 * Order with a screen_making stage + a design/placement (Pantone-linked),
 * a mapped physical screen, and the maker's own stage notes.
 *
 * @return array{0: Order, 1: OrderStage, 2: int}  [order, stage, pantoneId]
 */
function rhsmMakeOrderWithScreenStage(string $notes = 'Ingatan sa exposure.'): array
{
    $order = Order::create([
        'po_code'         => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_name'     => 'ACME Co',
        'workflow_status' => 'screen_making',
    ]);

    $stage = OrderStage::create([
        'order_id'     => $order->id,
        'stage'        => 'screen_making',
        'sequence'     => 6,
        'status'       => 'in_progress',
        'service_type' => 'in_house',
        'notes'        => $notes,
    ]);

    $pantoneId = DB::table('pantones')->insertGetId([
        'name' => 'White', 'hexcolor' => '#FFFFFF', 'pantone_code' => 'PMS 186 C',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $designId = DB::table('order_designs')->insertGetId([
        'order_id' => $order->id,
        'notes' => 'Body front design',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $placementId = DB::table('order_design_placements')->insertGetId([
        'order_design_id' => $designId,
        'type' => 'Body Front',
        'color_count' => 2,
        'pantones' => json_encode([$pantoneId]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $screenId = DB::table('screens')->insertGetId([
        'name' => 'S-014', 'size' => '16 x 8 in', 'mesh_count' => '160',
        'address' => 'Cabinet B', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('screen_assignments')->insert([
        'order_id' => $order->id,
        'placement_id' => $placementId,
        'screen_id' => $screenId,
        'color_index' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$order, $stage, $pantoneId];
}

// ── Service-level ───────────────────────────────────────────────

test('reviewSummary returns the Screen Making output block', function () {
    [$order] = rhsmMakeOrderWithScreenStage('Medyo manipis ang lines.');

    $summary = app(ScreenMakerPortalService::class)->reviewSummary($order);

    expect($summary)->toHaveKeys([
        'kind', 'design', 'placements', 'pantones_used', 'labels', 'screens', 'stage_notes',
    ]);
    expect($summary['kind'])->toBe('screen_making');
    expect($summary['stage_notes'])->toBe('Medyo manipis ang lines.');
    expect($summary['design'])->not->toBeNull();
    expect($summary['placements'])->toHaveCount(1);
    expect($summary['placements'][0]['type'])->toBe('Body Front');
    expect($summary['placements'][0]['color_count'])->toBe(2);
    expect($summary['pantones_used'][0]['pantone_code'])->toBe('PMS 186 C');
    expect($summary['screens'])->toHaveCount(1);
    expect($summary['screens'][0]['screen']['name'])->toBe('S-014');
    expect($summary['labels'])->toHaveKeys(['brand_label', 'care_label', 'label_design_url']);
});

// ── HTTP-level (BUG-010: wiring + new constructor dependency) ───

test('HTTP: stage-reviews payload includes stage_details keyed by the screen stage', function () {
    [$order, $stage] = rhsmMakeOrderWithScreenStage();
    $user = rhsmMakeUser();

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson("/api/v2/orders/{$order->id}/stage-reviews");

    $response->assertStatus(200);
    $details = $response->json('stage_details');
    expect($details)->toHaveKey((string) $stage->id);

    $sm = $details[(string) $stage->id] ?? $details[$stage->id];
    expect($sm['kind'])->toBe('screen_making');
    expect($sm['stage_notes'])->toBe('Ingatan sa exposure.');
    expect($sm['placements'])->toHaveCount(1);
    expect($sm['placements'][0]['pantones'])->toHaveCount(1);
});

test('HTTP: order without a screen_making stage has no screen block', function () {
    $order = Order::create([
        'po_code'         => 'ASH-2026-NOSMX1',
        'workflow_status' => 'inquiry',
    ]);
    // Cutter Rework CP1 — fixture moved off mass_cutting: cutting stages
    // now emit their own stage_details block, so a detail-free stage
    // (sample_sewing) keeps this test's intent (no SM stage → no block).
    OrderStage::create([
        'order_id' => $order->id,
        'stage'    => 'sample_sewing',
        'sequence' => 9,
        'status'   => 'in_progress',
    ]);
    $user = rhsmMakeUser();

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson("/api/v2/orders/{$order->id}/stage-reviews");

    $response->assertStatus(200);
    expect($response->json('stage_details'))->toBe([]);
});
