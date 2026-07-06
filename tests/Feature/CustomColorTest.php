<?php

use App\Models\CustomColor;
use App\Models\OrderDesignPlacement;
use App\Models\OrderStage;
use App\Models\Pantone;
use App\Services\CustomColorService;
use App\Services\OrderDesignPlacementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    foreach ([
        'role_has_permissions', 'model_has_permissions', 'model_has_roles', 'roles', 'permissions',
        'stage_audit_logs', 'order_design_placements', 'order_designs',
        'custom_colors', 'pantones', 'order_stages', 'orders', 'users',
    ] as $t) {
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

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('quotation_id')->nullable();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('shirt_color', 64)->nullable();
        $t->json('print_parts_json')->nullable();
        $t->string('workflow_status', 32)->default('inquiry');
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

    Schema::create('pantones', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('hexcolor');
        $t->string('pantone_code');
        $t->timestamps();
    });

    Schema::create('custom_colors', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->string('hexcolor');
        $t->string('pantone_code')->nullable();
        $t->unsignedInteger('pick_count')->default(0);
        $t->unsignedBigInteger('created_by')->nullable();
        $t->timestamps();
        $t->index('hexcolor', 'custom_colors_hex_idx');
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

    foreach (['portal.graphic-artist', 'action.upload-photos'] as $name) {
        DB::table('permissions')->insert([
            'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
        'role_has_permissions', 'model_has_permissions', 'model_has_roles', 'roles', 'permissions',
        'stage_audit_logs', 'order_design_placements', 'order_designs',
        'custom_colors', 'pantones', 'order_stages', 'orders', 'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Fixtures (ccx* — must not collide with other test files) ──────────

function ccxMakeUser(array $perms = ['portal.graphic-artist', 'action.upload-photos']): \App\Models\User
{
    $user = \App\Models\User::create([
        'name'          => 'Artist ' . uniqid(),
        'username'      => 'artist_' . uniqid(),
        'email'         => 'artist_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['graphic_artist'],
    ]);
    foreach ($perms as $p) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
    }
    if ($perms !== []) {
        $user->givePermissionTo($perms);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    return $user;
}

function ccxMakeOrderWithStage(): array
{
    $order = \App\Models\Order::create([
        'po_code'         => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_name'     => 'ACME Co',
        'workflow_status' => 'in_progress',
    ]);
    $stage = OrderStage::create([
        'order_id' => $order->id, 'stage' => 'graphic_artwork',
        'sequence' => 5, 'status' => 'in_progress', 'service_type' => 'in_house',
    ]);
    return [$order, $stage];
}

// ── CustomColorService ────────────────────────────────────────────────

test('findOrCreate creates a normalised row and auto-names blank to hex', function () {
    $svc = app(CustomColorService::class);

    $c = $svc->findOrCreate(['hexcolor' => '#0f8c8c']); // lower-case in
    expect($c->hexcolor)->toBe('#0F8C8C');              // stored upper
    expect($c->name)->toBe('#0F8C8C');                  // auto-named to hex
    expect((int) $c->pick_count)->toBe(1);
    expect(CustomColor::count())->toBe(1);

    // 3-digit shorthand expands.
    $short = $svc->findOrCreate(['hexcolor' => 'f00', 'name' => 'Red']);
    expect($short->hexcolor)->toBe('#FF0000');
    expect($short->name)->toBe('Red');
});

test('findOrCreate de-dups on hex regardless of input form and bumps pick_count', function () {
    $svc = app(CustomColorService::class);

    $a = $svc->findOrCreate(['hexcolor' => '#FF0000', 'name' => 'Red']);
    $b = $svc->findOrCreate(['hexcolor' => 'ff0000']);   // no #, lower
    $d = $svc->findOrCreate(['hexcolor' => 'f00']);      // shorthand

    expect($a->id)->toBe($b->id)->toBe($d->id);
    expect(CustomColor::count())->toBe(1);
    expect((int) $a->fresh()->pick_count)->toBe(3);
});

test('findOrCreate backfills a real name onto a hex-named row', function () {
    $svc = app(CustomColorService::class);

    $first = $svc->findOrCreate(['hexcolor' => '#123456']);       // name == hex
    expect($first->name)->toBe('#123456');

    $second = $svc->findOrCreate(['hexcolor' => '#123456', 'name' => 'Deep Blue']);
    expect($second->id)->toBe($first->id);
    expect($second->name)->toBe('Deep Blue');                     // upgraded
    expect(CustomColor::count())->toBe(1);
});

test('findOrCreate rejects a non-hex value', function () {
    app(CustomColorService::class)->findOrCreate(['hexcolor' => 'not-a-hex']);
})->throws(\InvalidArgumentException::class);

test('custom find-or-create never writes the pantones catalog', function () {
    Pantone::create(['name' => 'Fire Red', 'hexcolor' => '#C8102E', 'pantone_code' => 'PMS 186 C']);

    app(CustomColorService::class)->findOrCreate(['hexcolor' => '#0F8C8C', 'name' => 'Teal']);

    expect(Pantone::count())->toBe(1);        // untouched
    expect(CustomColor::count())->toBe(1);
});

test('options returns most-used first, tagged source=custom', function () {
    $svc = app(CustomColorService::class);
    $svc->findOrCreate(['hexcolor' => '#111111', 'name' => 'A']); // 1
    $svc->findOrCreate(['hexcolor' => '#222222', 'name' => 'B']); // 1
    $svc->findOrCreate(['hexcolor' => '#222222']);                // B -> 2

    $opts = $svc->options();
    expect($opts)->toHaveCount(2);
    expect($opts[0]['hexcolor'])->toBe('#222222');
    expect($opts[0]['source'])->toBe('custom');
});

test('options no-ops to [] when the table is absent', function () {
    Schema::dropIfExists('custom_colors');
    expect(app(CustomColorService::class)->options())->toBe([]);
});

// ── Enriched pantones storage / hydrate ───────────────────────────────

test('custom entry without id is find-or-created and stored as snapshot+reference', function () {
    [$order, $stage] = ccxMakeOrderWithStage();
    $user = ccxMakeUser();
    $svc  = app(OrderDesignPlacementService::class);

    $placement = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
        'color_count'    => 1,
        'pantones'       => [
            ['source' => 'custom', 'name' => 'My Teal', 'hexcolor' => '#0F8C8C'],
        ],
    ], $user);

    // A custom_colors row was created.
    expect(CustomColor::count())->toBe(1);
    $custom = CustomColor::first();
    expect($custom->hexcolor)->toBe('#0F8C8C');

    // Stored entry = snapshot + reference.
    $stored = $placement->pantones[0];
    expect($stored['source'])->toBe('custom');
    expect($stored['id'])->toBe($custom->id);
    expect($stored['name'])->toBe('My Teal');
    expect($stored['hexcolor'])->toBe('#0F8C8C');

    // present() surfaces the source for the picker.
    $present = $svc->present($placement->fresh());
    expect($present['pantones'][0]['source'])->toBe('custom');
    expect($present['pantones'][0]['id'])->toBe($custom->id);
});

test('custom entry with id is frozen and does not create a new row', function () {
    [$order, $stage] = ccxMakeOrderWithStage();
    $user = ccxMakeUser();
    $svc  = app(OrderDesignPlacementService::class);

    $custom = app(CustomColorService::class)->findOrCreate(['hexcolor' => '#123456', 'name' => 'Deep Blue']);
    expect((int) $custom->pick_count)->toBe(1);

    $placement = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Back',
        'pantones'       => [
            ['source' => 'custom', 'id' => $custom->id, 'name' => 'Deep Blue', 'hexcolor' => '#123456'],
        ],
    ], $user);

    $stored = $placement->pantones[0];
    expect($stored['source'])->toBe('custom');
    expect($stored['id'])->toBe($custom->id);
    expect($stored['hexcolor'])->toBe('#123456');

    // Referencing by id must not create a duplicate nor bump pick_count.
    expect(CustomColor::count())->toBe(1);
    expect((int) $custom->fresh()->pick_count)->toBe(1);
});

test('official id is stored as a bare int and hydrated as source=official', function () {
    [$order, $stage] = ccxMakeOrderWithStage();
    $user = ccxMakeUser();
    $svc  = app(OrderDesignPlacementService::class);

    $pantone = Pantone::create(['name' => 'Fire Red', 'hexcolor' => '#C8102E', 'pantone_code' => 'PMS 186 C']);

    $placement = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
        'pantones'       => [$pantone->id],
    ], $user);

    // Screen Maker-safe: raw storage stays a bare int.
    expect($placement->pantones[0])->toBe($pantone->id);

    $present = $svc->present($placement->fresh());
    expect($present['pantones'][0]['source'])->toBe('official');
    expect($present['pantones'][0]['id'])->toBe($pantone->id);
    expect($present['pantones'][0]['name'])->toBe('Fire Red');
});

test('legacy string and inline entries still store and hydrate', function () {
    [$order, $stage] = ccxMakeOrderWithStage();
    $user = ccxMakeUser();
    $svc  = app(OrderDesignPlacementService::class);

    $placement = $svc->upsert([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'type'           => 'Body Front',
        'pantones'       => ['PMS 3005 C', ['pantone_code' => 'PMS 109 C']],
    ], $user);

    // No custom rows created for legacy entries.
    expect(CustomColor::count())->toBe(0);
    expect($placement->pantones)->toHaveCount(2);

    $present = $svc->present($placement->fresh());
    expect($present['pantones'][0]['source'])->toBe('inline');
    expect($present['pantones'][0]['pantone_code'])->toBe('PMS 3005 C');
    expect($present['pantones'][1]['pantone_code'])->toBe('PMS 109 C');
});
