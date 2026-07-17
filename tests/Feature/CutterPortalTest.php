<?php

/**
 * Phase 5-B — Cutter Portal tests.
 *
 * Run with:
 *   php artisan test --filter=CutterPortalTest
 *
 * Coverage:
 *   1. buildContext() returns full payload for an active sample_cutting stage
 *   2. buildContext() rejects stages outside cutter scope (e.g., quotation)
 *   3. buildContext() rejects a stage that doesn't exist
 *   4. FabricLogService creates a log with correct auto-computed remaining
 *   5. FabricLogService rejects waste > fabric_used
 *   6. FabricLogService rejects writes to non-active stages
 *   7. FabricLogService rejects without stage_inputs.log_waste permission
 *   8. SampleUploadService creates an upload with sample_status='for_approval'
 *      and completed_at populated
 *   9. SampleUploadService update() transitions pending → for_approval and
 *      sets completed_at
 *  10. SampleUploadService rejects without action.upload-photos permission
 *
 * Cutter Rework CP1 additions:
 *  11. buildContext() order block carries the enriched Product-Details
 *      mirror (GA/SM shape) + the new placements / pantones_used /
 *      role_notes keys
 *  12. buildContext() hydrates the GA design output and returns ONLY the
 *      cutter's role-note thread
 *  13. reviewSummary() returns the Cutting output block (fabric entries
 *      incl. roll/batch refs + totals + stage notes) for a sample stage
 *  14. reviewSummary() reports phase='mass' + empty logs for an untouched
 *      mass_cutting stage
 *
 * Schema note (fixed in CP1): the hand-built material_requests table now
 * mirrors the real Phase 3 migration (stage_id + mr_code + reason +
 * approved_at) — the service queries those columns, so the old
 * order_stage_id-only shape could not satisfy buildContext.
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageFabricLog;
use App\Models\StageSampleUpload;
use App\Models\User;
use App\Services\CutterPortalService;
use App\Services\FabricLogService;
use App\Services\SampleUploadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'stage_audit_logs',
        'stage_sample_uploads',
        'stage_fabric_logs',
        'material_requests',
        'order_role_notes',
        'order_design_placements',
        'order_designs',
        'pantones',
        'model_has_permissions',
        'role_has_permissions',
        'model_has_roles',
        'permissions',
        'roles',
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('x');
        $t->timestamps();
        $t->softDeletes(); // User model uses SoftDeletes
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
        $t->timestamp('delayed_at')->nullable();
        $t->unsignedBigInteger('current_stage_id')->nullable();

        // Cutter Rework CP1 — Product Details mirror (same columns the
        // GA / SM portal tests build; read by the enriched orderDetails).
        $t->unsignedBigInteger('apparel_type_id')->nullable();
        $t->unsignedBigInteger('pattern_type_id')->nullable();
        $t->unsignedBigInteger('apparel_neckline_id')->nullable();
        $t->unsignedBigInteger('print_method_id')->nullable();
        $t->string('design_name')->nullable();
        $t->string('service_type', 64)->nullable();
        $t->string('print_service', 64)->nullable();
        $t->string('fabric_type', 64)->nullable();
        $t->string('fabric_supplier', 64)->nullable();
        $t->string('fabric_color', 64)->nullable();
        $t->string('thread_color', 64)->nullable();
        $t->string('ribbing_color', 64)->nullable();

        // Label specs + shared label design.
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

    // Cutter Rework CP1 — GA design output tables (buildContext now
    // hydrates the read-only Design Details section from these).
    Schema::create('pantones', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('hexcolor');
        $t->string('pantone_code');
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

    // Cutter Rework CP1 — Hub → cutter instruction thread source.
    Schema::create('order_role_notes', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('audience_role', 64);
        $t->unsignedBigInteger('author_user_id');
        $t->text('body');
        $t->timestamps();
    });

    // Spatie permission tables (minimal).
    Schema::create('roles', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name')->default('web');
        $t->timestamps();
    });

    Schema::create('permissions', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name')->default('web');
        $t->timestamps();
    });

    Schema::create('model_has_roles', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('model_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['permission_id', 'model_id', 'model_type']);
    });

    Schema::create('role_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->unsignedBigInteger('role_id');
        $t->primary(['permission_id', 'role_id']);
    });

    // Phase 5-B tables.
    Schema::create('stage_fabric_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id');
        // Phase 5-E — material_type tag (main_fabric, rib_trim, thread,
        // interfacing, other, waste). Nullable; mirrors the real migration.
        $t->string('material_type', 32)->nullable();
        $t->decimal('fabric_used_kg', 10, 2);
        $t->decimal('waste_kg', 10, 2)->default(0);
        $t->decimal('usable_remaining_kg', 10, 2)->default(0);
        $t->string('fabric_roll_id', 64)->nullable();
        $t->text('notes')->nullable();
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

    // Cutter Rework CP1 — mirrors the REAL Phase 3 migration (stage_id,
    // mr_code, reason, approved_at …) so the service's query works; the
    // old order_stage_id-only shape drifted from the data layer.
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

    // Permissions used by services.
    foreach (['stage_inputs.log_waste', 'stage_inputs.delete', 'action.upload-photos'] as $perm) {
        DB::table('permissions')->insert([
            'name' => $perm, 'guard_name' => 'web',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
        'stage_audit_logs',
        'stage_sample_uploads',
        'stage_fabric_logs',
        'material_requests',
        'order_role_notes',
        'order_design_placements',
        'order_designs',
        'pantones',
        'model_has_permissions',
        'role_has_permissions',
        'model_has_roles',
        'permissions',
        'roles',
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ─── Helpers ──────────────────────────────────────────────────────

function phase5b_makeUser(string $name, array $permissions = []): User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($permissions as $perm) {
        $pid = DB::table('permissions')->where('name', $perm)->value('id');
        if ($pid) {
            DB::table('model_has_permissions')->insert([
                'permission_id' => $pid,
                'model_type' => 'App\\Models\\User',
                'model_id' => $id,
            ]);
        }
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    return User::find($id);
}

function phase5b_makeOrderWithStage(string $stageSlug = 'sample_cutting', string $status = 'in_progress'): array
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-CT-' . uniqid(),
        'client_name' => 'Test Client',
        'client_brand' => 'TestBrand',
        'shirt_color' => 'Black',
        'special_print' => 'Silkscreen',
        'print_area' => 'Regular',
        'items_json' => json_encode([
            ['size' => 'M', 'quantity' => 30],
            ['size' => 'L', 'quantity' => 40],
            ['size' => 'XL', 'quantity' => 30],
        ]),
        // Cutter Rework CP1 — Production Details values so the enriched
        // orderDetails assertions have data (mirrors GA/SM fixtures).
        'design_name'     => 'Wow Design',
        'print_service'   => 'in_house',
        'fabric_type'     => 'Brushed Cotton',
        'fabric_supplier' => 'ABC Textile Supply',
        'fabric_color'    => 'Black',
        'thread_color'    => 'Black',
        'ribbing_color'   => 'Black',
        'workflow_status' => $stageSlug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => $stageSlug,
        'sequence' => 7,
        'status' => $status,
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'order_id'       => $orderId,
        'order_stage_id' => $stageId,
        'order'          => Order::find($orderId),
        'stage'          => OrderStage::find($stageId),
    ];
}

/**
 * Cutter Rework CP1 — attach a GA design + one placement (Pantone-linked)
 * to an order, so buildContext's read-only Design Details hydrates.
 *
 * @return array{0:int,1:int} [placementId, pantoneId]
 */
function phase5b_attachDesign(int $orderId): array
{
    $pantoneId = DB::table('pantones')->insertGetId([
        'name' => 'Jet Black', 'hexcolor' => '#111111', 'pantone_code' => 'Black 6 C',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $designId = DB::table('order_designs')->insertGetId([
        'order_id' => $orderId,
        'notes' => 'Body front design',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $placementId = DB::table('order_design_placements')->insertGetId([
        'order_design_id' => $designId,
        'type' => 'Front',
        'color_count' => 2,
        'pantones' => json_encode([$pantoneId]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$placementId, $pantoneId];
}

// ─── CutterPortalService tests ────────────────────────────────────

it('builds full context for an active sample_cutting stage', function () {
    $made = phase5b_makeOrderWithStage();

    $svc = new CutterPortalService();
    $ctx = $svc->buildContext($made['order_stage_id']);

    expect($ctx)->toHaveKeys([
        'order', 'stage', 'size_chart',
        'fabric_tracking', 'material_requests',
        'sample_uploads', 'activity_log', 'subcontract',
        // Cutter Rework CP1 — new keys.
        'placements', 'pantones_used', 'role_notes',
    ]);

    expect($ctx['order']['po_code'])->toStartWith('ASH-CT-');
    expect($ctx['order']['total_pcs'])->toBe(100);   // 30+40+30

    expect($ctx['stage']['phase'])->toBe('sample');
    expect($ctx['stage']['status'])->toBe('in_progress');

    expect($ctx['size_chart'])->toHaveCount(3);
    expect($ctx['fabric_tracking']['totals']['fabric_used_kg'])->toBe(0.0);

    // No design / no instructions yet → empty, not errors.
    expect($ctx['placements'])->toBe([]);
    expect($ctx['pantones_used'])->toBe([]);
    expect($ctx['role_notes'])->toHaveCount(0);
});

it('rejects context for a stage outside cutter scope', function () {
    $made = phase5b_makeOrderWithStage('graphic_artwork');

    $svc = new CutterPortalService();

    expect(fn () => $svc->buildContext($made['order_stage_id']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects context for a stage that does not exist', function () {
    $svc = new CutterPortalService();

    expect(fn () => $svc->buildContext(99999))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ─── Cutter Rework CP1 — enriched context ─────────────────────────

it('returns the enriched Product-Details order block (GA/SM shape)', function () {
    $made = phase5b_makeOrderWithStage();

    $ctx = (new CutterPortalService())->buildContext($made['order_stage_id']);

    expect($ctx['order'])->toHaveKeys([
        'shirt_color_hex',
        'apparel_type', 'pattern_type', 'apparel_neckline', 'print_method',
        'design_name', 'service_type', 'print_service',
        'fabric_type', 'fabric_supplier',
        'fabric_color', 'fabric_color_hex',
        'thread_color', 'thread_color_hex',
        'ribbing_color', 'ribbing_color_hex',
        'brand_label', 'care_label', 'label_design_url',
    ]);

    expect($ctx['order']['design_name'])->toBe('Wow Design');
    expect($ctx['order']['fabric_type'])->toBe('Brushed Cotton');
    expect($ctx['order']['fabric_supplier'])->toBe('ABC Textile Supply');
    // No fabric_swatches table here and no 'Black' pantone seeded → chip
    // resolution degrades to null instead of erroring (guarded lookups).
    expect($ctx['order']['fabric_color_hex'])->toBeNull();
});

it('hydrates the GA design output and only the cutter role-note thread', function () {
    $made = phase5b_makeOrderWithStage();
    [, $pantoneId] = phase5b_attachDesign($made['order_id']);

    $author = phase5b_makeUser('Hub Reviewer');
    DB::table('order_role_notes')->insert([
        [
            'order_id'       => $made['order_id'],
            'audience_role'  => 'cutter',
            'author_user_id' => $author->id,
            'body'           => 'I-double check ang grain line bago mag-cut.',
            'created_at'     => now(), 'updated_at' => now(),
        ],
        [
            'order_id'       => $made['order_id'],
            'audience_role'  => 'printer',
            'author_user_id' => $author->id,
            'body'           => 'Para sa printer lang ito.',
            'created_at'     => now(), 'updated_at' => now(),
        ],
    ]);

    $ctx = (new CutterPortalService())->buildContext($made['order_stage_id']);

    expect($ctx['placements'])->toHaveCount(1);
    expect($ctx['placements'][0]['type'])->toBe('Front');
    expect($ctx['placements'][0]['color_count'])->toBe(2);
    expect($ctx['placements'][0]['pantones'])->toHaveCount(1);
    expect($ctx['placements'][0]['pantones'][0]['id'])->toBe($pantoneId);
    expect($ctx['placements'][0]['pantones'][0]['pantone_code'])->toBe('Black 6 C');

    expect($ctx['pantones_used'])->toHaveCount(1);
    expect($ctx['pantones_used'][0]['hexcolor'])->toBe('#111111');

    expect($ctx['role_notes'])->toHaveCount(1);
    expect($ctx['role_notes'][0]['audience_role'])->toBe('cutter');
    expect($ctx['role_notes'][0]['body'])->toBe('I-double check ang grain line bago mag-cut.');
});

// ─── Cutter Rework CP1 — Review Hub summary ───────────────────────

it('reviewSummary returns the Cutting output block for a sample stage', function () {
    $user = phase5b_makeUser('Cutter', ['stage_inputs.log_waste']);
    $made = phase5b_makeOrderWithStage();

    $made['stage']->update(['notes' => 'Manipis ang tela, dinahan-dahan ko.']);

    DB::table('stage_fabric_logs')->insert([
        [
            'order_id' => $made['order_id'], 'order_stage_id' => $made['order_stage_id'],
            'logged_by_user_id' => $user->id,
            'fabric_used_kg' => 3.20, 'waste_kg' => 0.35, 'usable_remaining_kg' => 2.85,
            'fabric_roll_id' => 'BR-052024-08',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'order_id' => $made['order_id'], 'order_stage_id' => $made['order_stage_id'],
            'logged_by_user_id' => $user->id,
            'fabric_used_kg' => 1.80, 'waste_kg' => 0.15, 'usable_remaining_kg' => 1.65,
            'fabric_roll_id' => 'BR-052024-09',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $summary = (new CutterPortalService())
        ->reviewSummary($made['order'], $made['stage']->fresh());

    expect($summary)->toHaveKeys([
        'kind', 'phase', 'fabric_logs', 'fabric_totals', 'stage_notes',
    ]);
    expect($summary['kind'])->toBe('cutting');
    expect($summary['phase'])->toBe('sample');
    expect($summary['stage_notes'])->toBe('Manipis ang tela, dinahan-dahan ko.');

    expect($summary['fabric_logs'])->toHaveCount(2);
    // Logs come newest-first; the roll/batch refs must ride along.
    expect($summary['fabric_logs'][0]['fabric_roll_id'])->toBe('BR-052024-09');
    expect($summary['fabric_logs'][1]['fabric_roll_id'])->toBe('BR-052024-08');
    expect($summary['fabric_logs'][1]['logged_by']['name'])->toBe('Cutter');

    expect($summary['fabric_totals']['fabric_used_kg'])->toBe(5.0);
    expect($summary['fabric_totals']['waste_kg'])->toBe(0.5);
    expect($summary['fabric_totals']['usable_remaining_kg'])->toBe(4.5);
});

it('reviewSummary reports the mass phase with empty logs for an untouched stage', function () {
    $made = phase5b_makeOrderWithStage('mass_cutting');

    $summary = (new CutterPortalService())
        ->reviewSummary($made['order'], $made['stage']);

    expect($summary['kind'])->toBe('cutting');
    expect($summary['phase'])->toBe('mass');
    expect($summary['fabric_logs'])->toBe([]);
    expect($summary['fabric_totals']['fabric_used_kg'])->toBe(0.0);
    expect($summary['stage_notes'])->toBeNull();
});

// ─── FabricLogService tests ──────────────────────────────────────

it('creates a fabric log with auto-computed usable remaining', function () {
    $user = phase5b_makeUser('Cutter', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5b_makeOrderWithStage();

    $svc = new FabricLogService();
    $log = $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'fabric_used_kg' => 3.20,
        'waste_kg'       => 0.35,
        'fabric_roll_id' => 'BR-052024-08',
        'notes'          => 'maingat ang pag-cut',
    ], $user);

    expect((float) $log->fabric_used_kg)->toBe(3.20);
    expect((float) $log->waste_kg)->toBe(0.35);
    expect((float) $log->usable_remaining_kg)->toBe(2.85);  // auto: 3.20 - 0.35
    expect($log->fabric_roll_id)->toBe('BR-052024-08');
});

it('rejects fabric log when waste exceeds fabric used', function () {
    $user = phase5b_makeUser('Cutter', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5b_makeOrderWithStage();

    $svc = new FabricLogService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'fabric_used_kg' => 1.0,
        'waste_kg'       => 5.0,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects fabric log against a non-active stage', function () {
    $user = phase5b_makeUser('Cutter', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5b_makeOrderWithStage('sample_cutting', 'pending');

    $svc = new FabricLogService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'fabric_used_kg' => 1.0,
        'waste_kg'       => 0.1,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects fabric log without stage_inputs.log_waste permission', function () {
    $user = phase5b_makeUser('NoPerms');
    Auth::login($user);

    $made = phase5b_makeOrderWithStage();

    $svc = new FabricLogService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'fabric_used_kg' => 1.0,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ─── SampleUploadService tests ───────────────────────────────────

it('creates a sample upload with for_approval status and completed_at', function () {
    $user = phase5b_makeUser('Cutter', ['action.upload-photos']);
    Auth::login($user);

    $made = phase5b_makeOrderWithStage();

    $svc = new SampleUploadService();
    $upload = $svc->create([
        'order_id'         => $made['order_id'],
        'order_stage_id'   => $made['order_stage_id'],
        'photo_front_path' => 'sample-uploads/front/test.jpg',
        'photo_back_path'  => 'sample-uploads/back/test.jpg',
        'remarks'          => 'Tamang sukat at tahi.',
    ], $user);

    expect($upload->sample_status)->toBe(StageSampleUpload::STATUS_FOR_APPROVAL);
    expect($upload->completed_at)->not->toBeNull();
    expect($upload->photo_front_path)->toBe('sample-uploads/front/test.jpg');
    expect($upload->uploaded_by_user_id)->toBe($user->id);
});

it('transitions a sample upload from pending to for_approval', function () {
    $user = phase5b_makeUser('Cutter', ['action.upload-photos']);
    Auth::login($user);

    $made = phase5b_makeOrderWithStage();

    $svc = new SampleUploadService();

    // Step 1: create as pending (no completed_at)
    $upload = $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'sample_status'  => 'pending',
    ], $user);
    expect($upload->sample_status)->toBe('pending');
    expect($upload->completed_at)->toBeNull();

    // Step 2: mark as done
    $updated = $svc->update($upload->id, [
        'sample_status' => 'for_approval',
    ], $user);

    expect($updated->sample_status)->toBe('for_approval');
    expect($updated->completed_at)->not->toBeNull();
});

it('rejects sample upload without action.upload-photos permission', function () {
    $user = phase5b_makeUser('NoPerms');
    Auth::login($user);

    $made = phase5b_makeOrderWithStage();

    $svc = new SampleUploadService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'remarks'        => 'should fail',
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});
