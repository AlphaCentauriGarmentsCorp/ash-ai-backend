<?php

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PortalAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * MaterialPrepBadgeTest — RC-4b.
 *
 * The Material Prep sidebar badge must count ACTIVE PURCHASE REQUESTS, matching
 * that portal's worklist (MaterialPrepPortalService::myActiveRequests), not the
 * material_prep_* stages every other portal's badge uses. This pins that the
 * badge reads the PR count, honours the same visibility rule as the other roles
 * (oversight always; a worker only with portal.material-prep), and that the
 * oversight and worker views agree.
 *
 * Hand-built minimal schema (no RefreshDatabase). The material_prep branch of
 * badgeCounts reads only purchase_requests; the other roles (exercised by the
 * oversight and portal.cutter cases) read order_stages, so both are built here.
 * ACTIVE = pending/approved/ordered (MaterialPrepPortalService::ACTIVE_STATUSES);
 * received/cancelled are excluded.
 */

$MPB_TABLES = [
    'role_has_permissions',
    'model_has_permissions',
    'model_has_roles',
    'roles',
    'permissions',
    'order_stages',
    'orders',
    'purchase_requests',
    'users',
];

beforeEach(function () use ($MPB_TABLES) {
    foreach ($MPB_TABLES as $t) {
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

    // Only the columns activePurchaseRequestCount() touches.
    Schema::create('purchase_requests', function (Blueprint $t) {
        $t->id();
        $t->string('status', 24)->default('pending');
        $t->timestamps();
    });

    // Empty here; the non-material_prep roles query this in the oversight/cutter
    // cases. Columns match what activeCountForRole()/activeTasks() reference.
    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('stage');
        $t->string('status')->default('pending');
        $t->unsignedBigInteger('assigned_to')->nullable();
        $t->unsignedInteger('sequence')->default(0);
        $t->timestamp('started_at')->nullable();
        $t->timestamps();
    });

    // ── Spatie permission tables ─────────────────────────────────────
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

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () use ($MPB_TABLES) {
    foreach ($MPB_TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

/** A user with the given Spatie permissions and/or roles (guard web). */
function mpbUser(array $permissions = [], array $roles = []): User
{
    $user = User::create([
        'name'          => 'MP ' . uniqid(),
        'username'      => 'mp_' . uniqid(),
        'email'         => 'mp_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['worker'],
    ]);

    foreach ($permissions as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    foreach ($roles as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }
    if ($roles !== []) {
        $user->syncRoles($roles);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function mpbSeedPRs(array $statuses): void
{
    foreach ($statuses as $s) {
        PurchaseRequest::create(['status' => $s]);
    }
}

it('counts active purchase requests for a material-prep worker badge (RC-4b)', function () {
    $user = mpbUser(['portal.material-prep']);
    // 3 active (pending/approved/ordered) + 2 inactive (received/cancelled).
    mpbSeedPRs(['pending', 'approved', 'ordered', 'received', 'cancelled']);

    $counts = (new PortalAssignmentService())->badgeCounts($user);

    expect($counts)->toHaveKey('material_prep')
        ->and($counts['material_prep'])->toBe(3);
});

it('shows the same PR-based material_prep count to an oversight user (RC-4b)', function () {
    $user = mpbUser([], ['admin']); // Spatie admin role → oversight
    mpbSeedPRs(['pending', 'approved', 'received']); // 2 active

    $counts = (new PortalAssignmentService())->badgeCounts($user);

    // Material Prep is PR-based; the other stage roles are present as 0 (no
    // order_stages seeded), proving the special-case didn't disturb them.
    expect($counts['material_prep'])->toBe(2)
        ->and($counts)->toHaveKey('cutter')
        ->and($counts['cutter'])->toBe(0);
});

it('omits the material_prep badge for a worker who lacks portal.material-prep (RC-4b)', function () {
    $user = mpbUser(['portal.cutter']);
    mpbSeedPRs(['pending', 'approved']); // active PRs exist, but not this user's concern

    $counts = (new PortalAssignmentService())->badgeCounts($user);

    // Same visibility rule as every other role: no permission, not oversight →
    // no badge. Their own portal (cutter) is still present.
    expect($counts)->not->toHaveKey('material_prep')
        ->and($counts)->toHaveKey('cutter')
        ->and($counts['cutter'])->toBe(0);
});

it('reports zero active PRs as a present, zero material_prep badge for a worker (RC-4b)', function () {
    $user = mpbUser(['portal.material-prep']);
    mpbSeedPRs(['received', 'cancelled']); // none active

    $counts = (new PortalAssignmentService())->badgeCounts($user);

    expect($counts)->toHaveKey('material_prep')
        ->and($counts['material_prep'])->toBe(0);
});
