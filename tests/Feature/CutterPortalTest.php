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

    Schema::create('material_requests', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->string('status')->default('pending');
        $t->string('priority', 16)->default('normal');
        $t->date('needed_by')->nullable();
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

// ─── CutterPortalService tests ────────────────────────────────────

it('builds full context for an active sample_cutting stage', function () {
    $made = phase5b_makeOrderWithStage();

    $svc = new CutterPortalService();
    $ctx = $svc->buildContext($made['order_stage_id']);

    expect($ctx)->toHaveKeys([
        'order', 'stage', 'size_chart',
        'fabric_tracking', 'material_requests',
        'sample_uploads', 'activity_log',
    ]);

    expect($ctx['order']['po_code'])->toStartWith('ASH-CT-');
    expect($ctx['order']['total_pcs'])->toBe(100);   // 30+40+30

    expect($ctx['stage']['phase'])->toBe('sample');
    expect($ctx['stage']['status'])->toBe('in_progress');

    expect($ctx['size_chart'])->toHaveCount(3);
    expect($ctx['fabric_tracking']['totals']['fabric_used_kg'])->toBe(0.0);
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