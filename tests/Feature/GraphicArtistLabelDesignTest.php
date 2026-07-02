<?php

/**
 * GA Portal CP7 — shared Label Design upload tests.
 *
 * Run with:
 *   php artisan test --filter=GraphicArtistLabelDesignTest
 *
 * Coverage:
 *   1. upload sets orders.label_design_path and audit-logs
 *   2. replacing hard-deletes the previous physical file
 *   3. replacing does NOT delete an external-link previous value
 *   4. write against a completed stage is rejected
 *   5. actor without action.upload-photos is rejected
 *   6. HTTP: multipart POST /portal/graphic-artist/label-design end-to-end
 *
 * Helper names prefixed gald* to avoid Pest global-function collisions.
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Services\OrderLabelDesignService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

$GALD_TABLES = [
    'role_has_permissions',
    'model_has_permissions',
    'model_has_roles',
    'roles',
    'permissions',
    'stage_audit_logs',
    'order_stages',
    'orders',
    'users',
];

beforeEach(function () use ($GALD_TABLES) {
    foreach ($GALD_TABLES as $t) {
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
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('label_design_path')->nullable();
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
            'name'       => $name,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () use ($GALD_TABLES) {
    foreach ($GALD_TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Fixture builders (gald*) ────────────────────────────────────

function galdMakeUser(array $permissionNames = ['portal.graphic-artist', 'action.upload-photos']): \App\Models\User
{
    $user = \App\Models\User::create([
        'name'          => 'Artist ' . uniqid(),
        'username'      => 'artist_' . uniqid(),
        'email'         => 'artist_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['graphic_artist'],
    ]);

    foreach ($permissionNames as $pname) {
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name'       => $pname,
            'guard_name' => 'web',
        ]);
    }
    if ($permissionNames !== []) {
        $user->givePermissionTo($permissionNames);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    return $user;
}

function galdMakeOrderWithStage(string $status = 'in_progress', ?string $existingPath = null): array
{
    $order = Order::create([
        'po_code'           => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_name'       => 'ACME Co',
        'label_design_path' => $existingPath,
        'workflow_status'   => 'graphic_artwork',
    ]);
    $stage = OrderStage::create([
        'order_id'     => $order->id,
        'stage'        => 'graphic_artwork',
        'sequence'     => 5,
        'status'       => $status,
        'service_type' => 'in_house',
    ]);
    return [$order, $stage];
}

// ── Tests ───────────────────────────────────────────────────────

test('upload sets label_design_path and audit-logs', function () {
    Storage::fake('public');
    [$order, $stage] = galdMakeOrderWithStage();
    $user = galdMakeUser();

    $path = UploadedFile::fake()->image('label.png')->store('order-label-designs', 'public');

    $updated = app(OrderLabelDesignService::class)->upload([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'file_path'      => $path,
        'original_name'  => 'label.png',
    ], $user);

    expect($updated->label_design_path)->toBe($path);
    expect(
        StageAuditLog::where('action', OrderLabelDesignService::AUDIT_UPLOADED)->count(),
    )->toBe(1);
});

test('replacing hard-deletes the previous physical file', function () {
    Storage::fake('public');
    $oldPath = UploadedFile::fake()->image('old.png')->store('order-label-designs', 'public');
    [$order, $stage] = galdMakeOrderWithStage('in_progress', $oldPath);
    $user = galdMakeUser();

    $newPath = UploadedFile::fake()->image('new.png')->store('order-label-designs', 'public');

    app(OrderLabelDesignService::class)->upload([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'file_path'      => $newPath,
        'original_name'  => 'new.png',
    ], $user);

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($newPath);
});

test('replacing does not delete an external-link previous value', function () {
    Storage::fake('public');
    [$order, $stage] = galdMakeOrderWithStage('in_progress', 'https://canva.com/design/AAA/edit');
    $user = galdMakeUser();

    $newPath = UploadedFile::fake()->image('new.png')->store('order-label-designs', 'public');

    $updated = app(OrderLabelDesignService::class)->upload([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'file_path'      => $newPath,
        'original_name'  => 'new.png',
    ], $user);

    // No exception, link simply replaced.
    expect($updated->label_design_path)->toBe($newPath);
});

test('write against a completed stage is rejected', function () {
    [$order, $stage] = galdMakeOrderWithStage('completed');
    $user = galdMakeUser();

    app(OrderLabelDesignService::class)->upload([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'file_path'      => 'order-label-designs/x.png',
        'original_name'  => 'x.png',
    ], $user);
})->throws(\Illuminate\Validation\ValidationException::class);

test('actor without action.upload-photos is rejected', function () {
    [$order, $stage] = galdMakeOrderWithStage();
    $user = galdMakeUser(['portal.graphic-artist']);

    app(OrderLabelDesignService::class)->upload([
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'file_path'      => 'order-label-designs/x.png',
        'original_name'  => 'x.png',
    ], $user);
})->throws(\Illuminate\Validation\ValidationException::class);

test('HTTP: multipart POST /label-design uploads end-to-end', function () {
    Storage::fake('public');
    [$order, $stage] = galdMakeOrderWithStage();
    $user = galdMakeUser();

    $this->actingAs($user, 'sanctum');

    $response = $this->post('/api/v2/portal/graphic-artist/label-design', [
        'order_id'       => $order->id,
        'order_stage_id' => $stage->id,
        'file'           => UploadedFile::fake()->image('label.png', 300, 300),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(201);
    $data = $response->json('data');
    expect($data['label_design_path'])->not->toBeNull();
    expect($data['label_design_url'])->not->toBeNull();

    Storage::disk('public')->assertExists($data['label_design_path']);
    expect(Order::find($order->id)->label_design_path)->toBe($data['label_design_path']);
});
