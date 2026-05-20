<?php

/**
 * Phase 2 — Notification system tests.
 *
 * Run with:
 *     php artisan test --filter=NotificationServiceTest
 *
 * Same isolation strategy as WorkflowEngineTest – we build only the
 * tables we need so we don't depend on any other migration or seeder
 * (and so the test run can't be affected by MySQL-only SQL elsewhere).
 *
 * NOTE: Spatie's role lookup hits its own tables, so for these tests we
 * insert lightweight stand-ins for users + roles + the pivot. This keeps
 * the tests focused on the service's behaviour, not Spatie's plumbing.
 */

use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStage;
use App\Services\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------
// Schema bootstrap
// ---------------------------------------------------------------------

beforeEach(function () {
    foreach ([
        'notifications',
        'model_has_roles',
        'roles',
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->default('hashed');
        $table->timestamps();
    });

    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('po_code')->unique();
        $table->string('client_brand')->nullable();
        $table->string('workflow_status', 32)->default('inquiry');
        $table->timestamp('delayed_at')->nullable();
        $table->unsignedBigInteger('current_stage_id')->nullable();
        $table->timestamps();
    });

    Schema::create('order_stages', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('order_id');
        $table->text('stage');
        $table->unsignedSmallInteger('sequence')->default(0);
        $table->string('status')->default('pending');
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('delayed_at')->nullable();
        $table->unsignedBigInteger('assigned_to')->nullable();
        $table->string('assigned_role', 64)->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
    });

    // Spatie Permission tables (minimum required for User::role(...) query)
    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name')->default('web');
        $table->timestamps();
    });

    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('notifications', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('type', 64);
        $table->string('title');
        $table->text('body')->nullable();
        $table->json('data')->nullable();
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });

    // Pre-seed every role the NotificationService may query. Spatie's
    // User::role(['x', 'y', ...]) throws RoleDoesNotExist if any role
    // in the list doesn't exist in the DB, even when there are simply
    // no users with that role. Seeding empties keeps queries valid.
    $roles = [
        'superadmin', 'admin', 'general_manager',
        'csr', 'finance', 'purchasing', 'warehouse_manager',
        'graphic_artist', 'screen_maker', 'sample_maker',
        'cutter', 'printer', 'sewer', 'quality_assurance',
        'packer', 'driver', 'logistics',
    ];
    foreach ($roles as $role) {
        DB::table('roles')->insert([
            'name'       => $role,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Flush Spatie's permission cache so the freshly-inserted roles are
    // picked up immediately by User::role(...) queries.
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
        'notifications',
        'model_has_roles',
        'roles',
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function makeUserWithRole(string $name, string $role): int
{
    $userId = DB::table('users')->insertGetId([
        'name'     => $name,
        'email'    => $name . '@example.com',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Roles are pre-seeded in beforeEach. If a test asks for a role that
    // wasn't seeded, surface that loudly via insertGetId.
    $roleId = DB::table('roles')->where('name', $role)->value('id');
    if (! $roleId) {
        $roleId = DB::table('roles')->insertGetId([
            'name'       => $role,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('model_has_roles')->insert([
        'role_id'    => $roleId,
        'model_type' => 'App\\Models\\User',
        'model_id'   => $userId,
    ]);

    return $userId;
}

function makeOrderRow(): Order
{
    return Order::create([
        'po_code'      => 'ASH-NOTIF-' . uniqid(),
        'client_brand' => 'BrandX',
    ]);
}

function makeStageRow(Order $order, string $key, int $seq, ?int $assignedTo = null, ?string $role = null): OrderStage
{
    return OrderStage::create([
        'order_id'      => $order->id,
        'stage'         => $key,
        'sequence'      => $seq,
        'status'        => OrderStage::STATUS_IN_PROGRESS,
        'assigned_to'   => $assignedTo,
        'assigned_role' => $role,
    ]);
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('dispatches stageDelayed notifications to managers + csr + assignee', function () {
    $manager = makeUserWithRole('mgr', 'general_manager');
    $csr     = makeUserWithRole('csr', 'csr');
    $worker  = makeUserWithRole('worker', 'cutter');

    $order = makeOrderRow();
    $stage = makeStageRow($order, 'graphic_artwork', 5, $worker);

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->stageDelayed($stage, 'waiting on supplier');

    // Manager + CSR + worker all get one
    expect(Notification::where('user_id', $manager)->count())->toBe(1);
    expect(Notification::where('user_id', $csr)->count())->toBe(1);
    expect(Notification::where('user_id', $worker)->count())->toBe(1);

    $first = Notification::first();
    expect($first->type)->toBe('stage.delayed');
    expect($first->title)->toContain('Graphic Artwork');
    expect($first->body)->toContain('waiting on supplier');
    expect($first->read_at)->toBeNull();

    $data = is_array($first->data) ? $first->data : json_decode($first->data, true);
    expect($data['order_id'])->toBe($order->id);
    expect($data['stage_id'])->toBe($stage->id);
});

it('deduplicates recipients when a user has multiple matching roles', function () {
    // A single user with both manager AND csr roles should still get ONE notification.
    $userId = DB::table('users')->insertGetId([
        'name' => 'multi', 'email' => 'multi@x.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['general_manager', 'csr'] as $role) {
        $rid = DB::table('roles')->where('name', $role)->value('id');
        DB::table('model_has_roles')->insert([
            'role_id' => $rid, 'model_type' => 'App\\Models\\User', 'model_id' => $userId,
        ]);
    }

    $order = makeOrderRow();
    $stage = makeStageRow($order, 'inquiry', 1);

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->stageDelayed($stage, 'reason');

    expect(Notification::where('user_id', $userId)->count())->toBe(1);
});

it('notifies only the assigned user on stageAssigned', function () {
    $manager = makeUserWithRole('mgr', 'general_manager');
    $worker  = makeUserWithRole('worker', 'cutter');

    $order = makeOrderRow();
    $stage = makeStageRow($order, 'sample_creation', 7, $worker);

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->stageAssigned($stage, $worker);

    // Only the worker gets it - managers don't.
    expect(Notification::where('user_id', $worker)->count())->toBe(1);
    expect(Notification::where('user_id', $manager)->count())->toBe(0);

    $n = Notification::first();
    expect($n->type)->toBe('stage.assigned');
});

it('skips stageAssigned when no user id given (role-only assignment)', function () {
    $manager = makeUserWithRole('mgr', 'general_manager');

    $order = makeOrderRow();
    $stage = makeStageRow($order, 'inquiry', 1, null, 'csr');

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->stageAssigned($stage, null);

    expect(Notification::count())->toBe(0);
});

it('notifies users with the role that owns a stage on stageInProgress', function () {
    $cutter = makeUserWithRole('cutter1', 'cutter');
    $printer = makeUserWithRole('printer1', 'printer');

    $order = makeOrderRow();
    $stage = makeStageRow($order, 'sample_creation', 7, null, 'cutter');

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->stageInProgress($stage);

    expect(Notification::where('user_id', $cutter)->count())->toBe(1);
    expect(Notification::where('user_id', $printer)->count())->toBe(0);
});

it('orderCompleted pings managers + csr', function () {
    $manager = makeUserWithRole('mgr', 'general_manager');
    $csr     = makeUserWithRole('csr', 'csr');
    $cutter  = makeUserWithRole('cutter', 'cutter');

    $order = makeOrderRow();

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->orderCompleted($order);

    expect(Notification::where('user_id', $manager)->count())->toBe(1);
    expect(Notification::where('user_id', $csr)->count())->toBe(1);
    expect(Notification::where('user_id', $cutter)->count())->toBe(0);

    $n = Notification::first();
    expect($n->type)->toBe('order.completed');
    expect($n->title)->toContain($order->po_code);
});

it('listForUser returns paginated user-scoped notifications', function () {
    $userA = makeUserWithRole('a', 'csr');
    $userB = makeUserWithRole('b', 'csr');

    $order = makeOrderRow();
    $stage = makeStageRow($order, 'inquiry', 1);

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->stageDelayed($stage, 'r1');
    $svc->stageDelayed($stage, 'r2');

    // Both CSRs got both notifications.
    expect(Notification::where('user_id', $userA)->count())->toBe(2);
    expect(Notification::where('user_id', $userB)->count())->toBe(2);

    // listForUser should only return one user's slice.
    $page = $svc->listForUser($userA);
    expect($page->total())->toBe(2);
    foreach ($page->items() as $item) {
        expect($item->user_id)->toBe($userA);
    }
});

it('unreadCount and markRead behave correctly', function () {
    $user = makeUserWithRole('u', 'csr');

    $order = makeOrderRow();
    $stage = makeStageRow($order, 'inquiry', 1);

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->stageDelayed($stage, 'r1');
    $svc->stageDelayed($stage, 'r2');
    $svc->stageDelayed($stage, 'r3');

    expect($svc->unreadCount($user))->toBe(3);

    // Mark one read.
    $first = Notification::where('user_id', $user)->first();
    $svc->markRead($first->id, $user);

    expect($svc->unreadCount($user))->toBe(2);
    expect(Notification::find($first->id)->read_at)->not->toBeNull();
});

it('markAllRead clears every unread for the user only', function () {
    $userA = makeUserWithRole('a', 'csr');
    $userB = makeUserWithRole('b', 'csr');

    $order = makeOrderRow();
    $stage = makeStageRow($order, 'inquiry', 1);

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->stageDelayed($stage, 'r');

    $svc->markAllRead($userA);

    expect($svc->unreadCount($userA))->toBe(0);
    expect($svc->unreadCount($userB))->toBe(1); // userB unaffected
});

it('users cannot mark other users notifications as read', function () {
    $userA = makeUserWithRole('a', 'csr');
    $userB = makeUserWithRole('b', 'csr');

    $order = makeOrderRow();
    $stage = makeStageRow($order, 'inquiry', 1);

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);
    $svc->stageDelayed($stage, 'r');

    $userBNotif = Notification::where('user_id', $userB)->first();

    // userA tries to mark userB's notification as read.
    $result = $svc->markRead($userBNotif->id, $userA);

    // Returns null - access denied silently.
    expect($result)->toBeNull();
    // And the notification stays unread.
    expect(Notification::find($userBNotif->id)->read_at)->toBeNull();
});

it('quotationDecided fires correct type for approve vs reject', function () {
    makeUserWithRole('csr', 'csr');

    $order = makeOrderRow();

    /** @var NotificationService $svc */
    $svc = app(NotificationService::class);

    $svc->quotationDecided($order, 'approved');
    expect(Notification::where('type', 'quotation.approved')->count())->toBe(1);

    $svc->quotationDecided($order, 'rejected');
    expect(Notification::where('type', 'quotation.rejected')->count())->toBe(1);
});
