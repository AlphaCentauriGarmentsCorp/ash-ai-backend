<?php

/**
 * Phase 5-D — Stage Service Type Service tests.
 *
 * Run with:
 *   php artisan test --filter=StageServiceTypeTest
 *
 * Coverage:
 *   1. switch from in_house to subcontract clears assigned_to
 *   2. switch from subcontract to in_house cancels active SCA rows
 *   3. switch same-to-same is a no-op
 *   4. switch rejects non-flippable stages (e.g., quotation)
 *   5. switch rejects completed stages
 *   6. switch rejects invalid service_type values
 *   7. switch rejects without action.switch-service-type permission
 *   8. switch writes a stage_audit_log entry with from/to/notes
 */

use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Models\StageSubcontractAssignment;
use App\Models\User;
use App\Services\StageServiceTypeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'stage_audit_logs',
        'stage_subcontract_assignments',
        'subcontractors',
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

    // Spatie permission tables (minimal — must include all 5 to avoid
    // PermissionRegistrar trying to JOIN against a missing roles table
    // whenever $user->can() is called).
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

    Schema::create('subcontractors', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
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
        $t->text('notes')->nullable();
        $t->string('payment_terms', 64)->nullable();
        $t->decimal('agreed_price_per_sample', 10, 2)->nullable();
        $t->string('waybill_number', 64)->nullable();
        $t->string('gc_chat_link', 255)->nullable();
        $t->string('vendor_contact_number', 32)->nullable();
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

    DB::table('permissions')->insert([
        'name' => 'action.switch-service-type', 'guard_name' => 'web',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
        'stage_audit_logs',
        'stage_subcontract_assignments',
        'subcontractors',
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

function phase5d_makeUser(string $name, bool $withPerm = true): User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($withPerm) {
        $pid = DB::table('permissions')->where('name', 'action.switch-service-type')->value('id');
        DB::table('model_has_permissions')->insert([
            'permission_id' => $pid,
            'model_type' => 'App\\Models\\User',
            'model_id' => $id,
        ]);
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    return User::find($id);
}

function phase5d_makeStage(string $stageSlug = 'sample_cutting', string $serviceType = 'in_house', string $status = 'in_progress', ?int $assignedTo = null): OrderStage
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-S5D-' . uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => $stageSlug,
        'sequence' => 7,
        'status' => $status,
        'service_type' => $serviceType,
        'assigned_to' => $assignedTo,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return OrderStage::find($stageId);
}

// ─── Tests ────────────────────────────────────────────────────

it('switches in_house to subcontract and clears assigned_to', function () {
    $user = phase5d_makeUser('Manager');
    Auth::login($user);

    $stage = phase5d_makeStage('sample_cutting', 'in_house', 'in_progress', $user->id);
    expect($stage->assigned_to)->toBe($user->id);

    $svc = new StageServiceTypeService();
    $result = $svc->switch($stage->id, 'subcontract', $user);

    expect($result->service_type)->toBe('subcontract');
    expect($result->assigned_to)->toBeNull();
});

it('switches subcontract to in_house and cancels active SCA rows', function () {
    $user = phase5d_makeUser('Manager');
    Auth::login($user);

    $stage = phase5d_makeStage('sample_cutting', 'subcontract');

    $vendorId = DB::table('subcontractors')->insertGetId([
        'name' => 'ABC Printing', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Active assignment
    $scaActive = StageSubcontractAssignment::create([
        'order_id' => $stage->order_id,
        'order_stage_id' => $stage->id,
        'subcontractor_id' => $vendorId,
        'status' => 'out',
        'quantity_pcs' => 50,
        'rate_per_pcs' => 10,
        'total_amount' => 500,
    ]);

    // Already-returned assignment — should not be touched
    $scaReturned = StageSubcontractAssignment::create([
        'order_id' => $stage->order_id,
        'order_stage_id' => $stage->id,
        'subcontractor_id' => $vendorId,
        'status' => 'returned',
        'quantity_pcs' => 50,
        'rate_per_pcs' => 10,
        'total_amount' => 500,
    ]);

    $svc = new StageServiceTypeService();
    $result = $svc->switch($stage->id, 'in_house', $user);

    expect($result->service_type)->toBe('in_house');
    expect($scaActive->fresh()->status)->toBe('cancelled');
    expect($scaReturned->fresh()->status)->toBe('returned');  // untouched
});

it('returns unchanged stage when target type equals current', function () {
    $user = phase5d_makeUser('Manager');
    Auth::login($user);

    $stage = phase5d_makeStage('sample_cutting', 'in_house', 'in_progress', $user->id);

    $svc = new StageServiceTypeService();
    $result = $svc->switch($stage->id, 'in_house', $user);

    expect($result->service_type)->toBe('in_house');
    expect($result->assigned_to)->toBe($user->id);  // not cleared since no switch
});

it('rejects non-flippable stages', function () {
    $user = phase5d_makeUser('Manager');
    Auth::login($user);

    $stage = phase5d_makeStage('quotation', 'in_house');

    $svc = new StageServiceTypeService();

    expect(fn () => $svc->switch($stage->id, 'subcontract', $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects completed stages', function () {
    $user = phase5d_makeUser('Manager');
    Auth::login($user);

    $stage = phase5d_makeStage('sample_cutting', 'in_house', 'completed');

    $svc = new StageServiceTypeService();

    expect(fn () => $svc->switch($stage->id, 'subcontract', $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects invalid service_type values', function () {
    $user = phase5d_makeUser('Manager');
    Auth::login($user);

    $stage = phase5d_makeStage('sample_cutting');

    $svc = new StageServiceTypeService();

    expect(fn () => $svc->switch($stage->id, 'magic_mode', $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects users without action.switch-service-type permission', function () {
    $user = phase5d_makeUser('Random', withPerm: false);
    Auth::login($user);

    $stage = phase5d_makeStage('sample_cutting', 'in_house');

    $svc = new StageServiceTypeService();

    expect(fn () => $svc->switch($stage->id, 'subcontract', $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('writes an audit log entry on successful switch', function () {
    $user = phase5d_makeUser('Manager');
    Auth::login($user);

    $stage = phase5d_makeStage('sample_cutting', 'in_house');

    $svc = new StageServiceTypeService();
    $svc->switch($stage->id, 'subcontract', $user, 'Vendor needed for capacity');

    $log = StageAuditLog::where('order_stage_id', $stage->id)
        ->where('action', 'service_type_changed')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->from_status)->toBe('in_house');
    expect($log->to_status)->toBe('subcontract');
    expect($log->notes)->toContain('in_house → subcontract');
    expect($log->notes)->toContain('Vendor needed for capacity');
    expect($log->user_id)->toBe($user->id);
});
