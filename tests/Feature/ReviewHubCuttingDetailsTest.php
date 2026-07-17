<?php

/**
 * Cutter Rework CP1 — Review Hub Cutting details tests.
 *
 * Run with:
 *   php artisan test --filter=ReviewHubCuttingDetailsTest
 *
 * Coverage:
 *   1. CutterPortalService::reviewSummary returns the Cutting output block
 *      (fabric usage entries incl. roll/batch refs + totals + stage notes)
 *   2. HTTP: GET /orders/{id}/stage-reviews includes stage_details keyed
 *      by BOTH cutting stage ids — the cutter is the first role owning
 *      TWO stages per order, so the wiring is per-stage (BUG-010 lesson:
 *      exercises the controller's new constructor dependency + payload
 *      wiring end-to-end)
 *   3. HTTP: order without any cutting stage → no cutting block
 *
 * Schema mirrors ReviewHubScreenMakingDetailsTest (the whole hub read
 * path is exercised end-to-end) + stage_fabric_logs, which the cutting
 * summary reads. Helper names prefixed rhct* to avoid Pest
 * global-function collisions.
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Services\CutterPortalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

$RHCT_TABLES = [
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
    'stage_fabric_logs',
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

beforeEach(function () use ($RHCT_TABLES) {
    foreach ($RHCT_TABLES as $t) {
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
        // Label specs read by the portal reviewSummary services.
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

    // Cutter Rework CP1 — the cutting summary reads this table.
    Schema::create('stage_fabric_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id');
        $t->string('material_type', 32)->nullable();
        $t->decimal('fabric_used_kg', 10, 2);
        $t->decimal('waste_kg', 10, 2)->default(0);
        $t->decimal('usable_remaining_kg', 10, 2)->default(0);
        $t->string('fabric_roll_id', 64)->nullable();
        $t->text('notes')->nullable();
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

    foreach (['access.orders', 'action.upload-photos', 'portal.cutter'] as $name) {
        DB::table('permissions')->insert([
            'name'       => $name,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () use ($RHCT_TABLES) {
    foreach ($RHCT_TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Fixture builders (rhct*) ────────────────────────────────────

function rhctMakeUser(array $permissionNames = ['access.orders']): \App\Models\User
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
 * Order with BOTH cutting stages: sample_cutting (in progress, with two
 * fabric logs + the cutter's Save Notes) and mass_cutting (untouched).
 *
 * @return array{0: Order, 1: OrderStage, 2: OrderStage}
 *         [order, sampleStage, massStage]
 */
function rhctMakeOrderWithCuttingStages(string $notes = 'Manipis ang tela.'): array
{
    $order = Order::create([
        'po_code'         => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_name'     => 'ACME Co',
        'workflow_status' => 'sample_cutting',
    ]);

    $sampleStage = OrderStage::create([
        'order_id'     => $order->id,
        'stage'        => 'sample_cutting',
        'sequence'     => 7,
        'status'       => 'in_progress',
        'service_type' => 'in_house',
        'notes'        => $notes,
    ]);

    $massStage = OrderStage::create([
        'order_id'     => $order->id,
        'stage'        => 'mass_cutting',
        'sequence'     => 14,
        'status'       => 'pending',
        'service_type' => 'in_house',
    ]);

    $cutterId = DB::table('users')->insertGetId([
        'name'       => 'Cutter Worker',
        'email'      => 'cutter_' . uniqid() . '@test.local',
        'password'   => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('stage_fabric_logs')->insert([
        [
            'order_id' => $order->id, 'order_stage_id' => $sampleStage->id,
            'logged_by_user_id' => $cutterId,
            'fabric_used_kg' => 3.20, 'waste_kg' => 0.35, 'usable_remaining_kg' => 2.85,
            'fabric_roll_id' => 'BR-052024-08',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'order_id' => $order->id, 'order_stage_id' => $sampleStage->id,
            'logged_by_user_id' => $cutterId,
            'fabric_used_kg' => 1.80, 'waste_kg' => 0.15, 'usable_remaining_kg' => 1.65,
            'fabric_roll_id' => 'BR-052024-09',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    return [$order, $sampleStage, $massStage];
}

// ── Service-level ───────────────────────────────────────────────

test('reviewSummary returns the Cutting output block', function () {
    [$order, $sampleStage] = rhctMakeOrderWithCuttingStages('Dinahan-dahan ko ang pag-cut.');

    $summary = app(CutterPortalService::class)->reviewSummary($order, $sampleStage);

    expect($summary)->toHaveKeys([
        'kind', 'phase', 'fabric_logs', 'fabric_totals', 'stage_notes',
    ]);
    expect($summary['kind'])->toBe('cutting');
    expect($summary['phase'])->toBe('sample');
    expect($summary['stage_notes'])->toBe('Dinahan-dahan ko ang pag-cut.');
    expect($summary['fabric_logs'])->toHaveCount(2);
    expect($summary['fabric_logs'][0]['fabric_roll_id'])->toBe('BR-052024-09');
    expect($summary['fabric_logs'][0]['logged_by']['name'])->toBe('Cutter Worker');
    expect($summary['fabric_totals']['fabric_used_kg'])->toBe(5.0);
    expect($summary['fabric_totals']['waste_kg'])->toBe(0.5);
    expect($summary['fabric_totals']['usable_remaining_kg'])->toBe(4.5);
});

// ── HTTP-level (BUG-010: wiring + new constructor dependency) ───

test('HTTP: stage-reviews payload has per-stage cutting blocks for BOTH cutting stages', function () {
    [$order, $sampleStage, $massStage] = rhctMakeOrderWithCuttingStages();
    $user = rhctMakeUser();

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson("/api/v2/orders/{$order->id}/stage-reviews");

    $response->assertStatus(200);
    $details = $response->json('stage_details');

    expect($details)->toHaveKey((string) $sampleStage->id);
    expect($details)->toHaveKey((string) $massStage->id);

    $sample = $details[(string) $sampleStage->id] ?? $details[$sampleStage->id];
    expect($sample['kind'])->toBe('cutting');
    expect($sample['phase'])->toBe('sample');
    expect($sample['stage_notes'])->toBe('Manipis ang tela.');
    expect($sample['fabric_logs'])->toHaveCount(2);
    expect($sample['fabric_logs'][1]['fabric_roll_id'])->toBe('BR-052024-08');

    // The mass stage is untouched — it still gets its own block, with
    // its own (empty) logs. Per-stage separation is the point.
    $mass = $details[(string) $massStage->id] ?? $details[$massStage->id];
    expect($mass['kind'])->toBe('cutting');
    expect($mass['phase'])->toBe('mass');
    expect($mass['fabric_logs'])->toBe([]);
    expect($mass['stage_notes'])->toBeNull();
});

test('HTTP: order without a cutting stage has no cutting block', function () {
    $order = Order::create([
        'po_code'         => 'ASH-2026-NOCTX1',
        'workflow_status' => 'inquiry',
    ]);
    OrderStage::create([
        'order_id' => $order->id,
        'stage'    => 'sample_sewing',
        'sequence' => 9,
        'status'   => 'in_progress',
    ]);
    $user = rhctMakeUser();

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson("/api/v2/orders/{$order->id}/stage-reviews");

    $response->assertStatus(200);
    expect($response->json('stage_details'))->toBe([]);
});
