<?php

/**
 * Phase 5-C — Printer Portal tests.
 *
 * Run with:
 *   php artisan test --filter=PrinterPortalTest
 *
 * Coverage:
 *   1. buildContext returns full payload for active sample_printing
 *   2. buildContext rejects stages outside printer scope
 *   3. buildContext rejects unknown stage
 *   4. InkLogService creates with auto-computed remaining (3 decimals)
 *   5. InkLogService rejects waste > ink used
 *   6. InkLogService rejects writes to non-active stages
 *   7. InkLogService rejects without stage_inputs.log_waste permission
 *   8. ink_color is persisted correctly
 *
 * Printer Rework CP1 additions:
 *   9. buildContext() returns the enriched Order Details shape
 *  10. buildContext() returns hydrated placements + pantones_used
 *  11. buildContext() returns the Hub -> printer role-note thread
 *  12. reviewSummary() reports the printer's ink logs + stage notes
 *  13. reviewSummary() reports phase='mass' + empty logs for an
 *      untouched mass_printing stage
 *
 * NOTE — the three buildContext tests previously called
 * `new PrinterPortalService()`, but the service takes a required
 * MaterialPrepRequirementService constructor argument (added with the
 * material_details payload). That is an ArgumentCountError, so those
 * tests could not have been passing. They now resolve through the
 * container via app(). CutterPortalTest has the identical latent bug
 * (`new CutterPortalService()`) and needs the same one-line fix.
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageInkLog;
use App\Models\User;
use App\Services\InkLogService;
use App\Services\PrinterPortalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'stage_audit_logs',
        'stage_sample_uploads',
        'stage_ink_logs',
        'material_requests',
        'screen_assignments',
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

        // Printer Rework CP1 — Product Details mirror (the same columns
        // the GA / SM / Cutter portal tests build; read by the enriched
        // orderDetails()).
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
        $t->timestamp('started_at')->nullable();
        $t->timestamp('completed_at')->nullable();
        $t->timestamp('delayed_at')->nullable();
        $t->unsignedBigInteger('assigned_to')->nullable();
        $t->string('assigned_role', 64)->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    // Permission tables
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

    // Printer Rework CP1 — Pantone catalog, hydrated by placements() /
    // pantonesUsed() for the read-only Design Details section.
    Schema::create('pantones', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('hexcolor');
        $t->string('pantone_code');
        $t->timestamps();
    });

    // Printer Rework CP1 — Hub -> printer instruction thread source.
    Schema::create('order_role_notes', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('audience_role', 64);
        $t->unsignedBigInteger('author_user_id');
        $t->text('body');
        $t->timestamps();
    });

    // Phase 5-A table
    Schema::create('stage_ink_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id');
        $t->string('ink_color', 64)->nullable();
        $t->decimal('ink_used_kg', 10, 3);
        $t->decimal('ink_waste_kg', 10, 3)->default(0);
        $t->decimal('usable_remaining_kg', 10, 3)->default(0);
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

    Schema::create('material_requests', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('stage_id')->nullable();
        $t->string('mr_code', 32);
        $t->string('status')->default('pending');
        $t->text('reason')->nullable();
        $t->timestamp('approved_at')->nullable();
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

    Schema::create('screen_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('placement_id');
        $t->unsignedBigInteger('screen_id');
        $t->integer('color_index');
        $t->timestamps();
    });

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
        'stage_ink_logs',
        'material_requests',
        'screen_assignments',
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

// ─── Helpers ──────────────────────────────────────────────────

function phase5c_makeUser(string $name, array $permissions = []): User
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

function phase5c_makeOrderWithStage(string $stageSlug = 'sample_printing', string $status = 'in_progress'): array
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-PT-' . uniqid(),
        'client_name' => 'Test Client',
        'client_brand' => 'TestBrand',
        'shirt_color' => 'Black',
        'special_print' => 'Silkscreen',
        'print_area' => 'Regular',
        'items_json' => json_encode([
            ['size' => 'M', 'quantity' => 50],
            ['size' => 'L', 'quantity' => 50],
        ]),
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
    ];
}

// ─── PrinterPortalService tests ───────────────────────────────

it('builds full context for an active sample_printing stage', function () {
    $made = phase5c_makeOrderWithStage();

    $svc = app(PrinterPortalService::class);
    $ctx = $svc->buildContext($made['order_stage_id']);

    expect($ctx)->toHaveKeys([
        'order', 'stage', 'screen_details', 'print_placements',
        'ink_tracking', 'material_requests',
        'sample_uploads', 'activity_log',
        // Printer Rework CP1 — parity keys.
        'placements', 'pantones_used', 'role_notes',
    ]);

    expect($ctx['order']['po_code'])->toStartWith('ASH-PT-');
    expect($ctx['order']['total_pcs'])->toBe(100);   // 50+50
    expect($ctx['stage']['phase'])->toBe('sample');
    expect($ctx['stage']['status'])->toBe('in_progress');
    expect($ctx['ink_tracking']['totals']['ink_used_kg'])->toBe(0.0);
    expect($ctx['screen_details'])->toBe([]);
    expect($ctx['print_placements'])->toBe([]);
});

it('rejects context for a stage outside printer scope', function () {
    $made = phase5c_makeOrderWithStage('quotation');

    $svc = app(PrinterPortalService::class);

    expect(fn () => $svc->buildContext($made['order_stage_id']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects context for an unknown stage', function () {
    $svc = app(PrinterPortalService::class);

    expect(fn () => $svc->buildContext(99999))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ─── InkLogService tests ──────────────────────────────────────

it('creates an ink log with auto-computed remaining (3 decimals)', function () {
    $user = phase5c_makeUser('Printer', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5c_makeOrderWithStage();

    $svc = new InkLogService();
    $log = $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'ink_color'      => 'White',
        'ink_used_kg'    => 0.450,
        'ink_waste_kg'   => 0.080,
        'notes'          => 'Manipis ang print sa likod.',
    ], $user);

    expect((float) $log->ink_used_kg)->toBe(0.450);
    expect((float) $log->ink_waste_kg)->toBe(0.080);
    expect((float) $log->usable_remaining_kg)->toBe(0.370);   // 0.450 - 0.080
    expect($log->ink_color)->toBe('White');
});

it('rejects ink log when waste exceeds ink used', function () {
    $user = phase5c_makeUser('Printer', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5c_makeOrderWithStage();

    $svc = new InkLogService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'ink_used_kg'    => 0.100,
        'ink_waste_kg'   => 0.500,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects ink log against a non-active stage', function () {
    $user = phase5c_makeUser('Printer', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5c_makeOrderWithStage('sample_printing', 'pending');

    $svc = new InkLogService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'ink_used_kg'    => 0.100,
        'ink_waste_kg'   => 0.010,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects ink log without stage_inputs.log_waste permission', function () {
    $user = phase5c_makeUser('NoPerms');
    Auth::login($user);

    $made = phase5c_makeOrderWithStage();

    $svc = new InkLogService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'ink_used_kg'    => 0.100,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('persists ink_color correctly', function () {
    $user = phase5c_makeUser('Printer', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5c_makeOrderWithStage();

    $svc = new InkLogService();

    $log1 = $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'ink_color'      => 'Pantone 186 C',
        'ink_used_kg'    => 0.250,
    ], $user);

    expect($log1->ink_color)->toBe('Pantone 186 C');

    // Also allow null
    $log2 = $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'ink_used_kg'    => 0.100,
    ], $user);

    expect($log2->ink_color)->toBeNull();
});

// ─── Printer Rework CP1 — parity + Review Hub tests ───────────

it('returns the enriched Order Details shape', function () {
    $made = phase5c_makeOrderWithStage();

    DB::table('orders')->where('id', $made['order_id'])->update([
        'design_name'    => 'Krusada Front Hit',
        'fabric_type'    => 'CVC 240',
        'fabric_color'   => 'Black',
        'thread_color'   => 'White',
        'ribbing_color'  => 'Black',
        'print_service'  => 'Regular',
        'fabric_supplier'=> 'Test Supplier',
    ]);

    $ctx = app(PrinterPortalService::class)->buildContext($made['order_stage_id']);

    // Every pre-rework key still present (purely additive change).
    expect($ctx['order'])->toHaveKeys([
        'id', 'po_code', 'client_name', 'client_brand', 'shirt_color',
        'special_print', 'print_area', 'total_pcs', 'workflow_status', 'notes',
    ]);

    // ...plus the enriched Product Details mirror the shared
    // OrderDetailsSectionGA layout reads.
    expect($ctx['order'])->toHaveKeys([
        'shirt_color_hex', 'apparel_type', 'pattern_type', 'apparel_neckline',
        'print_method', 'design_name', 'service_type', 'print_service',
        'fabric_type', 'fabric_supplier', 'fabric_color', 'fabric_color_hex',
        'thread_color', 'thread_color_hex', 'ribbing_color', 'ribbing_color_hex',
        'brand_label', 'care_label', 'label_design_url',
    ]);

    expect($ctx['order']['design_name'])->toBe('Krusada Front Hit');
    expect($ctx['order']['fabric_type'])->toBe('CVC 240');
    expect($ctx['order']['print_service'])->toBe('Regular');
});

it('hydrates placements and pantones_used from the GA design', function () {
    $made = phase5c_makeOrderWithStage();

    $pantoneId = DB::table('pantones')->insertGetId([
        'name' => 'Reflex Blue', 'hexcolor' => '#001489',
        'pantone_code' => 'PMS 2758 C',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $designId = DB::table('order_designs')->insertGetId([
        'order_id' => $made['order_id'],
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('order_design_placements')->insert([
        'order_design_id' => $designId,
        'type' => 'Front',
        'color_count' => 1,
        'pantones' => json_encode([$pantoneId]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ctx = app(PrinterPortalService::class)->buildContext($made['order_stage_id']);

    expect($ctx['placements'])->toHaveCount(1);
    expect($ctx['placements'][0]['type'])->toBe('Front');
    expect($ctx['placements'][0]['color_count'])->toBe(1);
    expect($ctx['placements'][0]['pantones'][0]['name'])->toBe('Reflex Blue');
    expect($ctx['placements'][0]['pantones'][0]['source'])->toBe('official');

    expect($ctx['pantones_used'])->toHaveCount(1);
    expect($ctx['pantones_used'][0]['pantone_code'])->toBe('PMS 2758 C');

    // The printer's OWN mockup guide is untouched by the rework and still
    // carries the raw (unhydrated) pantone ids — owner decision was to
    // keep both sections.
    expect($ctx['print_placements'])->toHaveCount(1);
    expect($ctx['print_placements'][0]['pantones'])->toBe([$pantoneId]);
});

it('returns the Hub to printer instruction thread', function () {
    $made = phase5c_makeOrderWithStage();
    $author = phase5c_makeUser('Reviewer');

    DB::table('order_role_notes')->insert([
        'order_id' => $made['order_id'],
        'audience_role' => 'printer',
        'author_user_id' => $author->id,
        'body' => 'Doblehin ang pass sa likod, medyo light kanina.',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A note aimed at another role must NOT leak into the printer thread.
    DB::table('order_role_notes')->insert([
        'order_id' => $made['order_id'],
        'audience_role' => 'cutter',
        'author_user_id' => $author->id,
        'body' => 'Para sa cutter lang ito.',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ctx = app(PrinterPortalService::class)->buildContext($made['order_stage_id']);

    expect($ctx['role_notes'])->toHaveCount(1);
    expect($ctx['role_notes'][0]['body'])
        ->toBe('Doblehin ang pass sa likod, medyo light kanina.');
});

it('reviewSummary reports the printer ink logs and stage notes', function () {
    $made = phase5c_makeOrderWithStage();
    $user = phase5c_makeUser('Printer Joe');

    DB::table('order_stages')->where('id', $made['order_stage_id'])->update([
        'notes' => 'Naka-2 pass ang black para solid.',
    ]);

    DB::table('stage_ink_logs')->insert([
        'order_id' => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'logged_by_user_id' => $user->id,
        'ink_color' => 'Black',
        'ink_used_kg' => 1.250,
        'ink_waste_kg' => 0.250,
        'usable_remaining_kg' => 1.000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $order = Order::find($made['order_id']);
    $stage = OrderStage::find($made['order_stage_id']);

    $summary = app(PrinterPortalService::class)->reviewSummary($order, $stage);

    expect($summary['kind'])->toBe('printing');
    expect($summary['phase'])->toBe('sample');
    expect($summary['ink_logs'])->toHaveCount(1);
    expect($summary['ink_logs'][0]['ink_color'])->toBe('Black');
    expect($summary['ink_totals']['ink_used_kg'])->toBe(1.25);
    expect($summary['ink_totals']['ink_waste_kg'])->toBe(0.25);
    expect($summary['stage_notes'])->toBe('Naka-2 pass ang black para solid.');
});

it('reviewSummary reports phase mass and empty logs for an untouched stage', function () {
    $made = phase5c_makeOrderWithStage('mass_printing');

    $order = Order::find($made['order_id']);
    $stage = OrderStage::find($made['order_stage_id']);

    $summary = app(PrinterPortalService::class)->reviewSummary($order, $stage);

    expect($summary['kind'])->toBe('printing');
    expect($summary['phase'])->toBe('mass');
    expect($summary['ink_logs'])->toBe([]);
    expect($summary['ink_totals']['ink_used_kg'])->toBe(0.0);
    expect($summary['stage_notes'])->toBeNull();
});
