<?php

/**
 * Phase 5-E — Sewer Portal tests.
 *
 * Run with:
 *   php artisan test --filter=SewerPortalTest
 *
 * Coverage:
 *   1. buildContext returns full payload for an active sample_creation
 *   2. buildContext rejects stages outside sewer scope
 *   3. buildContext rejects unknown stage
 *   4. buildContext returns subcontract info when service_type=subcontract
 *   5. SewerMaterialLogService creates a log with material_type
 *   6. SewerMaterialLogService rejects invalid material_type
 *   7. SewerMaterialLogService rejects waste > used
 *   8. SewerMaterialLogService rejects without permission
 *   9. materialsUsage groups logs by material type with totals
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageFabricLog;
use App\Models\StageSubcontractAssignment;
use App\Models\User;
use App\Services\SewerMaterialLogService;
use App\Services\SewerPortalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'stage_audit_logs',
        'stage_sample_uploads',
        'stage_fabric_logs',
        'stage_subcontract_assignments',
        'subcontractors',
        'material_requests',
        'order_design_placements',
        'order_designs',
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

    // All 5 Spatie tables (lesson from 5-D hotfix)
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

    Schema::create('subcontractors', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->string('address')->nullable();
        $t->string('contact_number')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_subcontract_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('subcontractor_id');
        $t->integer('quantity_pcs')->default(0);
        $t->decimal('rate_per_pcs', 10, 2)->default(0);
        $t->decimal('total_amount', 10, 2)->default(0);
        $t->string('status')->default('pending');
        $t->timestamp('sent_at')->nullable();
        $t->timestamp('returned_at')->nullable();
        $t->timestamp('expected_return_at')->nullable();
        $t->string('turnover_method', 64)->nullable();
        $t->text('notes')->nullable();
        $t->string('payment_terms', 64)->nullable();
        $t->decimal('agreed_price_per_sample', 10, 2)->nullable();
        $t->string('waybill_number', 64)->nullable();
        $t->string('gc_chat_link', 255)->nullable();
        $t->string('vendor_contact_number', 32)->nullable();
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
        'stage_audit_logs', 'stage_sample_uploads', 'stage_fabric_logs',
        'stage_subcontract_assignments', 'subcontractors',
        'material_requests', 'order_design_placements', 'order_designs',
        'model_has_permissions', 'role_has_permissions', 'model_has_roles',
        'permissions', 'roles',
        'order_stages', 'orders', 'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ─── Helpers ───────────────────────────────────────────────────

function phase5e_makeUser(string $name, array $permissions = []): User
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

function phase5e_makeStage(string $stageSlug = 'sample_creation', string $serviceType = 'in_house', string $status = 'in_progress'): array
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-SW-' . uniqid(),
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
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => $stageSlug,
        'sequence' => 7,
        'status' => $status,
        'service_type' => $serviceType,
        'started_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'order_id'       => $orderId,
        'order_stage_id' => $stageId,
    ];
}

// ─── SewerPortalService tests ──────────────────────────────────

it('builds full context for an active sample_creation stage', function () {
    $made = phase5e_makeStage();

    $svc = new SewerPortalService();
    $ctx = $svc->buildContext($made['order_stage_id']);

    expect($ctx)->toHaveKeys([
        'order', 'stage', 'sample_details', 'measurements',
        'materials_usage', 'material_requests',
        'sample_uploads', 'activity_log', 'subcontract',
    ]);

    expect($ctx['order']['po_code'])->toStartWith('ASH-SW-');
    expect($ctx['order']['total_pcs'])->toBe(100);
    expect($ctx['stage']['phase'])->toBe('sample');
    expect($ctx['stage']['service_type'])->toBe('in_house');
    expect($ctx['materials_usage']['grand_totals']['used_kg'])->toBe(0.0);
    expect($ctx['subcontract'])->toBeNull();  // not subcontracted
});

it('rejects context for a stage outside sewer scope', function () {
    $made = phase5e_makeStage('quotation');

    $svc = new SewerPortalService();

    expect(fn () => $svc->buildContext($made['order_stage_id']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects context for an unknown stage', function () {
    $svc = new SewerPortalService();

    expect(fn () => $svc->buildContext(99999))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('returns subcontract info when service_type is subcontract', function () {
    $made = phase5e_makeStage('sample_creation', 'subcontract');

    $vendorId = DB::table('subcontractors')->insertGetId([
        'name' => 'Lita Garments',
        'address' => 'Caloocan City',
        'contact_number' => '0917 123 4567',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    StageSubcontractAssignment::create([
        'order_id' => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'subcontractor_id' => $vendorId,
        'status' => 'out',
        'quantity_pcs' => 50,
        'rate_per_pcs' => 180,
        'total_amount' => 9000,
        'sent_at' => now(),
        'expected_return_at' => now()->addDays(2),
        'turnover_method' => 'Lalamove',
        'payment_terms' => 'After Turnover / 7 days',
        'waybill_number' => 'LMP123456789',
    ]);

    $svc = new SewerPortalService();
    $ctx = $svc->buildContext($made['order_stage_id']);

    expect($ctx['subcontract'])->not->toBeNull();
    expect($ctx['subcontract']['has_assignment'])->toBeTrue();
    expect($ctx['subcontract']['vendor']['name'])->toBe('Lita Garments');
    expect($ctx['subcontract']['turnover_method'])->toBe('Lalamove');
    expect($ctx['subcontract']['expected_return_at'])->not->toBeNull();
});

// ─── SewerMaterialLogService tests ─────────────────────────────

it('creates a material log with material_type', function () {
    $user = phase5e_makeUser('Sewer', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5e_makeStage();

    $svc = new SewerMaterialLogService();
    $log = $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'material_type'  => 'main_fabric',
        'fabric_used_kg' => 0.65,
        'waste_kg'       => 0.08,
    ], $user);

    expect($log->material_type)->toBe('main_fabric');
    expect((float) $log->fabric_used_kg)->toBe(0.65);
    expect((float) $log->waste_kg)->toBe(0.08);
    expect((float) $log->usable_remaining_kg)->toBe(0.57);
});

it('rejects invalid material_type values', function () {
    $user = phase5e_makeUser('Sewer', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5e_makeStage();

    $svc = new SewerMaterialLogService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'material_type'  => 'unicorn_horn',
        'fabric_used_kg' => 1.0,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects material log when waste exceeds used', function () {
    $user = phase5e_makeUser('Sewer', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5e_makeStage();

    $svc = new SewerMaterialLogService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'material_type'  => 'main_fabric',
        'fabric_used_kg' => 0.5,
        'waste_kg'       => 1.0,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects material log without stage_inputs.log_waste permission', function () {
    $user = phase5e_makeUser('NoPerms');
    Auth::login($user);

    $made = phase5e_makeStage();

    $svc = new SewerMaterialLogService();

    expect(fn () => $svc->create([
        'order_id'       => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'material_type'  => 'thread',
        'fabric_used_kg' => 0.1,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('groups materials_usage by material_type with totals', function () {
    $user = phase5e_makeUser('Sewer', ['stage_inputs.log_waste']);
    Auth::login($user);

    $made = phase5e_makeStage();

    $svc = new SewerMaterialLogService();

    // 2 logs for main_fabric
    $svc->create([
        'order_id' => $made['order_id'], 'order_stage_id' => $made['order_stage_id'],
        'material_type' => 'main_fabric', 'fabric_used_kg' => 0.5, 'waste_kg' => 0.05,
    ], $user);
    $svc->create([
        'order_id' => $made['order_id'], 'order_stage_id' => $made['order_stage_id'],
        'material_type' => 'main_fabric', 'fabric_used_kg' => 0.3, 'waste_kg' => 0.02,
    ], $user);

    // 1 log for thread
    $svc->create([
        'order_id' => $made['order_id'], 'order_stage_id' => $made['order_stage_id'],
        'material_type' => 'thread', 'fabric_used_kg' => 0.1, 'waste_kg' => 0.0,
    ], $user);

    $portal = new SewerPortalService();
    $ctx = $portal->buildContext($made['order_stage_id']);

    $byMaterial = collect($ctx['materials_usage']['by_material']);

    $mainFabric = $byMaterial->firstWhere('material_type', 'main_fabric');
    expect($mainFabric)->not->toBeNull();
    expect($mainFabric['used_kg'])->toBe(0.8);   // 0.5 + 0.3
    expect($mainFabric['waste_kg'])->toBe(0.07); // 0.05 + 0.02
    expect($mainFabric['entry_count'])->toBe(2);

    $thread = $byMaterial->firstWhere('material_type', 'thread');
    expect($thread)->not->toBeNull();
    expect($thread['used_kg'])->toBe(0.1);
    expect($thread['entry_count'])->toBe(1);

    // Grand totals
    expect($ctx['materials_usage']['grand_totals']['used_kg'])->toBe(0.9);
    expect($ctx['materials_usage']['grand_totals']['waste_kg'])->toBe(0.07);
});
