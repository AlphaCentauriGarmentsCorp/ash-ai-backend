<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * BadgeSummaryTest — CP-3, GET /api/v2/badges.
 *
 * The consolidated badge endpoint returns portals always, and awaiting /
 * pending_approvals ONLY to users who can open the matching list. This pins
 * that field-visibility per permission set and the counts themselves.
 *
 * Hand-built minimal schema (no RefreshDatabase), in the DashboardApprovalCardTest
 * style. The endpoint only calls the COUNT methods (awaitingCount/count) and
 * PortalAssignmentService::badgeCounts, so the tables it touches are: orders +
 * order_payments (payment counts, via whereHas('order') on the SoftDeletes scope),
 * order_stages (badge counts; empty here), and the five Spatie tables for the
 * permission gates. present() is never invoked, so no order.items/currentStage
 * relations are needed.
 */

$BADGE_TABLES = [
    'role_has_permissions',
    'model_has_permissions',
    'model_has_roles',
    'roles',
    'permissions',
    'stage_reviews',
    'order_stages',
    'order_payments',
    'orders',
    'users',
];

beforeEach(function () use ($BADGE_TABLES) {
    foreach ($BADGE_TABLES as $t) {
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
        $t->softDeletes(); // User model uses SoftDeletes
    });

    // Only the columns the endpoint touches. client_* are never read (present()
    // isn't called), so they're omitted; deleted_at is required because the
    // payment counts filter through whereHas('order') on the SoftDeletes scope.
    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('order_payments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('payment_type', 16);
        $t->decimal('amount', 10, 2);
        $t->string('status', 24)->default('waiting');
        $t->timestamp('uploaded_at')->nullable();
        $t->timestamps();
    });

    // badgeCounts() queries this; empty in these tests. Include the columns its
    // WHERE clause references so the query builds even with no rows.
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

    // Not reached while the stage queue is empty (buildTaskList early-returns
    // before forRevisionStageIds); created defensively so a query can't miss it.
    Schema::create('stage_reviews', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_stage_id');
        $t->string('decision')->nullable();
        $t->timestamps();
    });

    // ── Spatie permission tables (mirrors CsrDashboardTest) ──────────
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

afterEach(function () use ($BADGE_TABLES) {
    foreach ($BADGE_TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

/** A user with domain access to ash and exactly the given permissions. */
function badgeUser(array $permissions = []): User
{
    $user = User::create([
        'name'          => 'Badge ' . uniqid(),
        'username'      => 'badge_' . uniqid(),
        'email'         => 'badge_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['worker'],
    ]);

    foreach ($permissions as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** N distinct orders each with one for_verification payment (feeds pending_approvals). */
function badgeSeedForVerification(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $order = Order::create(['po_code' => 'ASH-BDG-FV-' . uniqid()]);
        OrderPayment::create([
            'order_id'     => $order->id,
            'payment_type' => OrderPayment::TYPE_SAMPLE,
            'amount'       => 1000,
            'status'       => OrderPayment::STATUS_FOR_VERIFICATION,
            'uploaded_at'  => now(),
        ]);
    }
}

/** N distinct orders each with one waiting payment (feeds awaiting). */
function badgeSeedWaiting(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $order = Order::create(['po_code' => 'ASH-BDG-W-' . uniqid()]);
        OrderPayment::create([
            'order_id'     => $order->id,
            'payment_type' => OrderPayment::TYPE_BALANCE,
            'amount'       => 500,
            'status'       => OrderPayment::STATUS_WAITING,
            'uploaded_at'  => now(),
        ]);
    }
}

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v2/badges')->assertUnauthorized();
});

it('gives a plain worker only portals — no awaiting, no pending_approvals', function () {
    $user = badgeUser(['portal.cutter']);
    badgeSeedForVerification(2);
    badgeSeedWaiting(1);

    $json = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v2/badges')
        ->assertOk()
        ->json();

    expect($json)->toHaveKey('portals')
        ->and($json)->not->toHaveKey('awaiting')
        ->and($json)->not->toHaveKey('pending_approvals');
});

it('gives a CSR the awaiting count but not pending_approvals', function () {
    $user = badgeUser(['portal.csr']);
    badgeSeedWaiting(3);
    badgeSeedForVerification(2); // must NOT bleed into awaiting

    $json = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v2/badges')
        ->assertOk()
        ->json();

    expect($json)->toHaveKey('awaiting')
        ->and($json['awaiting'])->toBe(3)
        ->and($json)->not->toHaveKey('pending_approvals');
});

it('gives an approver the pending_approvals count but not awaiting', function () {
    $user = badgeUser(['action.verify-payment']);
    badgeSeedForVerification(2);
    badgeSeedWaiting(1); // must NOT bleed into pending_approvals

    $json = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v2/badges')
        ->assertOk()
        ->json();

    expect($json)->toHaveKey('pending_approvals')
        ->and($json['pending_approvals'])->toBe(2)
        ->and($json)->not->toHaveKey('awaiting');
});

it('gives a user with both gates all three counts', function () {
    $user = badgeUser(['portal.csr', 'action.verify-payment']);
    badgeSeedForVerification(2);
    badgeSeedWaiting(3);

    $json = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v2/badges')
        ->assertOk()
        ->json();

    expect($json)->toHaveKeys(['portals', 'awaiting', 'pending_approvals'])
        ->and($json['awaiting'])->toBe(3)
        ->and($json['pending_approvals'])->toBe(2);
});
