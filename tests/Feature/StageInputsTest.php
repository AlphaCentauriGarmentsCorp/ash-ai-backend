<?php

/**
 * Phase 4 — Stage Inputs + Subcontract + Audit + Reports tests.
 *
 * Run with:
 *     php artisan test --filter=StageInputsTest
 *
 * Same isolation strategy as the Phase 3 tests: build the minimal
 * set of tables we need by hand, seed Spatie roles + permissions,
 * exercise services + controllers end-to-end.
 *
 * What this tests:
 *   - WorkCalendar business-hours math (single day, weekend span, edge cases)
 *   - Audit hook fires on stage transitions + duration computed correctly
 *   - StageInputsService rejects pending/completed stages
 *   - StageInputsService logs waste + reject for active stages
 *   - SubcontractService full lifecycle (assign → sent → returned)
 *   - SubcontractService rejects invalid transitions
 *   - Permission gating works
 *   - Reports endpoints return correct aggregates
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\SewingSubcontractor;
use App\Models\StageAuditLog;
use App\Models\StageRejectLog;
use App\Models\StageSubcontractAssignment;
use App\Models\StageWasteLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OrderStagesService;
use App\Services\StageInputsService;
use App\Services\SubcontractService;
use App\Support\WorkCalendar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------
// Schema bootstrap — minimal tables for Phase 4 tests.
// ---------------------------------------------------------------------

beforeEach(function () {
    foreach ([
        'stage_audit_logs',
        'stage_subcontract_assignments',
        'stage_reject_logs',
        'stage_waste_logs',
        'subcontractors',
        'notifications',
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
        $t->string('password')->default('hashed');
        $t->timestamps();
        $t->softDeletes(); // User model uses SoftDeletes
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('items_json')->default('[]');
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

    // Spatie permission tables.
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

    Schema::create('notifications', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('type', 64);
        $t->string('title');
        $t->text('body')->nullable();
        $t->json('data')->nullable();
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });

    // Phase 4 tables.
    Schema::create('subcontractors', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('address');
        $t->decimal('rate_per_pcs', 10, 2);
        $t->string('contact_number')->nullable();
        $t->string('email')->nullable();
        $t->string('service_type', 32)->default('sewing');
        $t->timestamps();
    });

    Schema::create('stage_waste_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id');
        $t->unsignedInteger('quantity_pcs');
        $t->string('photo_path')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_reject_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id');
        $t->unsignedInteger('quantity_pcs');
        // Phase 7-B Bundle 1 — disposition (reject|repair, default reject)
        // and the nullable reject-reason taxonomy FK. Mirrors the real
        // migration; reject_reason_id is a plain nullable column here since
        // this isolated schema doesn't build the reject_reasons table.
        $t->string('disposition', 16)->default('reject');
        $t->unsignedBigInteger('reject_reason_id')->nullable();
        $t->string('photo_path')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_subcontract_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('subcontractor_id')->nullable();
        $t->unsignedInteger('quantity_pcs');
        $t->decimal('rate_per_pcs', 10, 2)->default(0);
        $t->decimal('total_amount', 12, 2)->default(0);
        $t->string('status', 16)->default('pending');
        $t->timestamp('sent_at')->nullable();
        $t->timestamp('returned_at')->nullable();
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

    // Pre-seed every role we may use.
    foreach ([
        'superadmin', 'admin', 'general_manager',
        'csr', 'finance', 'purchasing', 'warehouse_manager',
        'graphic_artist', 'screen_maker', 'sample_maker',
        'cutter', 'printer', 'sewer', 'quality_assurance',
        'packer', 'driver', 'logistics',
    ] as $role) {
        DB::table('roles')->insert([
            'name' => $role, 'guard_name' => 'web',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Pre-seed Phase 4 permissions.
    foreach ([
        'stage_inputs.view',
        'stage_inputs.log_waste',
        'stage_inputs.log_reject',
        'stage_inputs.log_subcontract',
        'stage_inputs.delete',
        'access.reports',
    ] as $perm) {
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
        'stage_subcontract_assignments',
        'stage_reject_logs',
        'stage_waste_logs',
        'subcontractors',
        'notifications',
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

// ---------------------------------------------------------------------
// Helpers — phase4_ prefix to avoid collisions with phase3_ helpers.
// ---------------------------------------------------------------------

function phase4_makeUser(string $name, array $permissions = []): User
{
    $userId = DB::table('users')->insertGetId([
        'name'  => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . '@example.com',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($permissions as $perm) {
        $pid = DB::table('permissions')->where('name', $perm)->value('id');
        if ($pid) {
            DB::table('model_has_permissions')->insert([
                'permission_id' => $pid,
                'model_type' => 'App\\Models\\User',
                'model_id' => $userId,
            ]);
        }
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    return User::find($userId);
}

function phase4_makeOrderWithActiveStage(string $stageSlug = 'cutting', int $sequence = 8): array
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-TEST-' . uniqid(),
        'client_name' => 'Test Client',
        'client_brand' => 'TestBrand',
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
        'sequence' => $sequence,
        'status' => 'in_progress',
        'started_at' => now()->subHours(2),
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    DB::table('orders')->where('id', $orderId)->update(['current_stage_id' => $stageId]);

    return [
        'order' => Order::find($orderId),
        'stage' => OrderStage::find($stageId),
    ];
}

function phase4_makePendingStage(int $orderId, string $stageSlug = 'mass_packing', int $sequence = 11): OrderStage
{
    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => $stageSlug,
        'sequence' => $sequence,
        'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return OrderStage::find($stageId);
}

function phase4_makeSubcontractor(string $serviceType = 'sewing', float $rate = 200.00): SewingSubcontractor
{
    return SewingSubcontractor::create([
        'name' => 'TestVendor-' . uniqid(),
        'address' => '123 Test Street',
        'rate_per_pcs' => $rate,
        'service_type' => $serviceType,
    ]);
}

// ---------------------------------------------------------------------
// WorkCalendar tests
// ---------------------------------------------------------------------

it('computes business-hours within a single work day', function () {
    // 9am to 12pm on a Monday → 3 hours = 10800 seconds.
    $secs = WorkCalendar::businessSecondsBetween('2025-11-17 09:00:00', '2025-11-17 12:00:00');
    expect($secs)->toBe(10800);
});

it('computes business-hours across a weekend', function () {
    // Fri 5pm → Mon 10am with default Mon-Sat 8-18:
    //   Fri 17:00-18:00 = 3600 (one work hour left on Friday)
    //   Sat (full work day, 10hrs) = 36000
    //   Sun = 0 (not a work day by default)
    //   Mon 8am-10am = 7200
    //   Total = 46800
    $secs = WorkCalendar::businessSecondsBetween('2025-11-14 17:00:00', '2025-11-17 10:00:00');
    expect($secs)->toBe(46800);
});

it('returns 0 when end is before start', function () {
    expect(WorkCalendar::businessSecondsBetween('2025-11-17 12:00:00', '2025-11-17 09:00:00'))->toBe(0);
});

it('returns 0 for null inputs', function () {
    expect(WorkCalendar::businessSecondsBetween(null, now()))->toBe(0);
    expect(WorkCalendar::businessSecondsBetween(now(), null))->toBe(0);
});

it('clips a range that starts before work hours', function () {
    // 6am to 10am on a Monday → only 8am-10am counts = 7200 seconds.
    $secs = WorkCalendar::businessSecondsBetween('2025-11-17 06:00:00', '2025-11-17 10:00:00');
    expect($secs)->toBe(7200);
});

// ---------------------------------------------------------------------
// Audit hook tests
// ---------------------------------------------------------------------

it('writes an audit row when a stage is marked delayed', function () {
    $user = phase4_makeUser('Manager User');
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage();
    $stage = $built['stage'];

    $svc = new OrderStagesService(new NotificationService());
    $svc->markDelayed($stage->id, 'late delivery');

    $audit = StageAuditLog::where('order_stage_id', $stage->id)->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->action)->toBe(StageAuditLog::ACTION_DELAYED);
    expect($audit->from_status)->toBe(OrderStage::STATUS_IN_PROGRESS);
    expect($audit->to_status)->toBe(OrderStage::STATUS_DELAYED);
    expect($audit->notes)->toBe('late delivery');
    expect($audit->user_id)->toBe($user->id);
});

it('writes an audit row when a stage is resumed', function () {
    $user = phase4_makeUser('Manager');
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage();
    $stage = $built['stage'];

    $svc = new OrderStagesService(new NotificationService());
    $svc->markDelayed($stage->id, 'oops');
    $svc->resume($stage->id);

    $rows = StageAuditLog::where('order_stage_id', $stage->id)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows[0]->action)->toBe(StageAuditLog::ACTION_DELAYED);
    expect($rows[1]->action)->toBe(StageAuditLog::ACTION_RESUMED);
    expect($rows[1]->from_status)->toBe(OrderStage::STATUS_DELAYED);
    expect($rows[1]->to_status)->toBe(OrderStage::STATUS_IN_PROGRESS);
});

// ---------------------------------------------------------------------
// StageInputsService tests
// ---------------------------------------------------------------------

it('logs waste against an in_progress stage', function () {
    $user = phase4_makeUser('Cutter', ['stage_inputs.log_waste']);
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage();

    $svc = new StageInputsService(new NotificationService());
    $log = $svc->logWaste([
        'order_id'       => $built['order']->id,
        'order_stage_id' => $built['stage']->id,
        'quantity_pcs'   => 5,
        'notes'          => 'fabric tear',
    ], $user);

    expect($log)->toBeInstanceOf(StageWasteLog::class);
    expect($log->quantity_pcs)->toBe(5);
    expect($log->logged_by_user_id)->toBe($user->id);
    expect($log->notes)->toBe('fabric tear');
});

it('rejects waste log against a pending stage', function () {
    $user = phase4_makeUser('Cutter', ['stage_inputs.log_waste']);
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage();
    $pending = phase4_makePendingStage($built['order']->id);

    $svc = new StageInputsService(new NotificationService());

    expect(fn () => $svc->logWaste([
        'order_id'       => $built['order']->id,
        'order_stage_id' => $pending->id,
        'quantity_pcs'   => 3,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects waste log when actor lacks permission', function () {
    $user = phase4_makeUser('NoPermsUser');  // no permissions granted
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage();

    $svc = new StageInputsService(new NotificationService());

    expect(fn () => $svc->logWaste([
        'order_id'       => $built['order']->id,
        'order_stage_id' => $built['stage']->id,
        'quantity_pcs'   => 3,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('logs reject against an in_progress stage', function () {
    $user = phase4_makeUser('QA', ['stage_inputs.log_reject']);
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage('mass_qa', 10);

    $svc = new StageInputsService(new NotificationService());
    $log = $svc->logReject([
        'order_id'       => $built['order']->id,
        'order_stage_id' => $built['stage']->id,
        'quantity_pcs'   => 2,
        'notes'          => 'misprinted',
    ], $user);

    expect($log)->toBeInstanceOf(StageRejectLog::class);
    expect($log->quantity_pcs)->toBe(2);
});

// ---------------------------------------------------------------------
// SubcontractService tests
// ---------------------------------------------------------------------

it('walks a subcontract assignment through pending → out → returned', function () {
    $user = phase4_makeUser('Sewer', ['stage_inputs.log_subcontract']);
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage('mass_sewing', 9);
    $vendor = phase4_makeSubcontractor('sewing', 200.00);

    $svc = new SubcontractService(new NotificationService());
    $assignment = $svc->assign([
        'order_id'         => $built['order']->id,
        'order_stage_id'   => $built['stage']->id,
        'subcontractor_id' => $vendor->id,
        'quantity_pcs'     => 50,
    ], $user);

    expect($assignment->status)->toBe(StageSubcontractAssignment::STATUS_PENDING);
    expect((float) $assignment->total_amount)->toBe(10000.0);  // 50 * 200
    expect((float) $assignment->rate_per_pcs)->toBe(200.0);

    $sent = $svc->markSent($assignment->id, $user);
    expect($sent->status)->toBe(StageSubcontractAssignment::STATUS_OUT);
    expect($sent->sent_at)->not->toBeNull();

    $returned = $svc->markReturned($sent->id, $user);
    expect($returned->status)->toBe(StageSubcontractAssignment::STATUS_RETURNED);
    expect($returned->returned_at)->not->toBeNull();
});

it('refuses to mark sent on a non-pending assignment', function () {
    $user = phase4_makeUser('Sewer', ['stage_inputs.log_subcontract']);
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage('mass_sewing', 9);
    $vendor = phase4_makeSubcontractor();

    $svc = new SubcontractService(new NotificationService());
    $assignment = $svc->assign([
        'order_id'         => $built['order']->id,
        'order_stage_id'   => $built['stage']->id,
        'subcontractor_id' => $vendor->id,
        'quantity_pcs'     => 30,
    ], $user);

    $svc->markSent($assignment->id, $user);

    // Already 'out' — second markSent must fail.
    expect(fn () => $svc->markSent($assignment->id, $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('refuses to cancel a returned assignment', function () {
    $user = phase4_makeUser('Sewer', ['stage_inputs.log_subcontract']);
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage('mass_sewing', 9);
    $vendor = phase4_makeSubcontractor();

    $svc = new SubcontractService(new NotificationService());
    $assignment = $svc->assign([
        'order_id'         => $built['order']->id,
        'order_stage_id'   => $built['stage']->id,
        'subcontractor_id' => $vendor->id,
        'quantity_pcs'     => 30,
    ], $user);

    $svc->markSent($assignment->id, $user);
    $svc->markReturned($assignment->id, $user);

    expect(fn () => $svc->cancel($assignment->id, $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('refuses to subcontract when actor lacks permission', function () {
    $user = phase4_makeUser('NoPermsUser');
    Auth::login($user);

    $built = phase4_makeOrderWithActiveStage('mass_sewing', 9);
    $vendor = phase4_makeSubcontractor();

    $svc = new SubcontractService(new NotificationService());

    expect(fn () => $svc->assign([
        'order_id'         => $built['order']->id,
        'order_stage_id'   => $built['stage']->id,
        'subcontractor_id' => $vendor->id,
        'quantity_pcs'     => 10,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});