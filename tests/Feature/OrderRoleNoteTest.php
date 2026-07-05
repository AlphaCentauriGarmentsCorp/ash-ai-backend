<?php

/**
 * Role-directed order notes — OrderRoleNoteService tests.
 *
 * Run with:
 *     php artisan test --filter=OrderRoleNoteTest
 *
 * Same in-memory SQLite isolation strategy as the other Phase tests:
 * hand-built minimal schema + pre-seeded Spatie roles so the
 * NotificationService fan-out queries don't throw RoleDoesNotExist.
 *
 * Coverage:
 *   1. create() appends an entry (author loaded, body trimmed)
 *   2. blank body throws ValidationException
 *   3. non-workflow audience_role throws ValidationException
 *   4. create() notifies ONLY users holding the audience role
 *   5. forOrderGrouped() groups threads by role, chronological within each
 *   6. forRole() returns a single role's thread (and empty for others)
 */

use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderRoleNote;
use App\Models\User;
use App\Services\OrderRoleNoteService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

$TABLES = [
    'order_role_notes', 'notifications',
    'role_has_permissions', 'model_has_permissions', 'permissions',
    'model_has_roles', 'roles',
    'orders', 'users',
];

beforeEach(function () use ($TABLES) {
    foreach ($TABLES as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('hashed');
        $t->timestamps();
        // The User model uses SoftDeletes, so Eloquent appends
        // `deleted_at is null` to every User query — the hand-built
        // schema must include the column.
        $t->softDeletes();
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('status')->default('Pending Approval');
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
        $t->index(['order_id', 'audience_role']);
    });

    Schema::create('roles', function (Blueprint $t) {
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

    // NotificationService fan-out resolves through Spatie; keep the
    // permission tables present (empty) so the registrar never throws.
    Schema::create('permissions', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name')->default('web');
        $t->timestamps();
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

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () use ($TABLES) {
    foreach ($TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function orn_makeUser(string $name, ?string $role = null): User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name, 'email' => $name . '@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($role) {
        $roleId = DB::table('roles')->where('name', $role)->value('id');
        DB::table('model_has_roles')->insert([
            'role_id'    => $roleId,
            'model_type' => User::class,
            'model_id'   => $id,
        ]);
    }

    return User::find($id);
}

function orn_order(): Order
{
    return Order::create([
        'po_code' => 'ASH-ORN-' . uniqid(),
        'status'  => 'Pending Approval',
    ]);
}

function orn_svc(): OrderRoleNoteService
{
    return app(OrderRoleNoteService::class);
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('appends an entry with the author loaded and the body trimmed', function () {
    $order  = orn_order();
    $author = orn_makeUser('csr1', 'csr');

    $note = orn_svc()->create(
        $order,
        $author,
        'graphic_artist',
        '  Pakigawa mas bold ang front print.  ',
    );

    expect($note->audience_role)->toBe('graphic_artist')
        ->and($note->body)->toBe('Pakigawa mas bold ang front print.')
        ->and($note->author->name)->toBe('csr1')
        ->and(OrderRoleNote::count())->toBe(1);

    $summary = orn_svc()->summarize($note);
    expect($summary)->toHaveKeys([
        'id', 'order_id', 'audience_role', 'body', 'author', 'created_at',
    ])->and($summary['author']['name'])->toBe('csr1');
});

it('rejects an empty or whitespace-only body', function () {
    orn_svc()->create(orn_order(), orn_makeUser('csr1', 'csr'), 'graphic_artist', '   ');
})->throws(ValidationException::class);

it('rejects an audience_role that is not a workflow role', function () {
    orn_svc()->create(orn_order(), orn_makeUser('csr1', 'csr'), 'accountant', 'Hi');
})->throws(ValidationException::class);

it('notifies only the users holding the audience role', function () {
    $order  = orn_order();
    $author = orn_makeUser('csr1', 'csr');
    $ga1    = orn_makeUser('ga1', 'graphic_artist');
    $ga2    = orn_makeUser('ga2', 'graphic_artist');
    orn_makeUser('cutter1', 'cutter');

    $note = orn_svc()->create($order, $author, 'graphic_artist', 'Check the Pantone list.');

    expect(Notification::count())->toBe(2)
        ->and(Notification::pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$ga1->id, $ga2->id])->sort()->values()->all());

    $n = Notification::first();
    expect($n->type)->toBe('role_note.created')
        ->and($n->data['order_id'])->toBe($order->id)
        ->and($n->data['audience_role'])->toBe('graphic_artist')
        ->and($n->data['role_note_id'])->toBe($note->id);
});

it('groups threads by audience_role for the hub payload, chronological within each', function () {
    $order  = orn_order();
    $author = orn_makeUser('admin1', 'admin');

    orn_svc()->create($order, $author, 'graphic_artist', 'First GA instruction.');
    orn_svc()->create($order, $author, 'cutter', 'Fabric is in rack B.');
    orn_svc()->create($order, $author, 'graphic_artist', 'Second GA instruction.');

    $grouped = orn_svc()->forOrderGrouped($order->id);

    expect($grouped)->toHaveKey('graphic_artist')
        ->and($grouped)->toHaveKey('cutter')
        ->and($grouped['graphic_artist'])->toHaveCount(2)
        ->and($grouped['cutter'])->toHaveCount(1)
        ->and($grouped['graphic_artist'][0]['body'])->toBe('First GA instruction.')
        ->and($grouped['graphic_artist'][1]['body'])->toBe('Second GA instruction.')
        ->and($grouped['graphic_artist'][0]['author']['name'])->toBe('admin1');
});

it('returns a single role thread for the portal payload and empty for others', function () {
    $order  = orn_order();
    $author = orn_makeUser('admin1', 'admin');

    orn_svc()->create($order, $author, 'graphic_artist', 'A');
    orn_svc()->create($order, $author, 'graphic_artist', 'B');

    $ga = orn_svc()->forRole($order->id, 'graphic_artist');
    expect($ga)->toHaveCount(2)
        ->and($ga[0]['body'])->toBe('A')
        ->and($ga[1]['body'])->toBe('B');

    expect(orn_svc()->forRole($order->id, 'screen_maker'))->toBeEmpty();
});
