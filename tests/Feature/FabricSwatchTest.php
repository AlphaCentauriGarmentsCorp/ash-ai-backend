<?php

/**
 * Phase 6-B — Fabric Swatch Catalog tests.
 *
 * Run with:
 *   php artisan test --filter=FabricSwatchTest
 *
 * Coverage:
 *   1. list returns swatches filtered by fabric_type + gsm
 *   2. create swatch with valid pantone link returns 201
 *   3. swatch context surfaces stock status from linked material
 *   4. HTTP: GET /csr/fabric-swatches returns 200
 */

use App\Models\FabricSwatch;
use App\Models\Materials;
use App\Models\Pantone;
use App\Services\FabricSwatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',
        'fabric_swatches',
        'pantones',
        'materials',
        'suppliers',
        'users',
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
        $t->softDeletes(); // User model uses SoftDeletes
    });

    Schema::create('suppliers', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    Schema::create('pantones', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('hexcolor');
        $t->string('pantone_code');
        $t->timestamps();
    });

    Schema::create('materials', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->decimal('stock_on_hand', 12, 3)->default(0);
        $t->timestamps();
    });

    Schema::create('fabric_swatches', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('pantone_id')->nullable();
        $t->string('hex_color', 8)->nullable();
        $t->string('fabric_type', 64)->nullable();
        $t->smallInteger('gsm')->unsigned()->nullable();
        $t->string('collection', 64)->nullable();
        $t->unsignedBigInteger('supplier_id')->nullable();
        $t->unsignedBigInteger('material_id')->nullable();
        $t->string('color_family', 32)->nullable();
        $t->string('photo_path', 255)->nullable();
        $t->text('notes')->nullable();
        $t->unsignedInteger('pick_count')->default(0);
        $t->timestamps();
    });

    // Spatie tables
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

    Storage::fake('public');
});

afterEach(function () {
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',
        'fabric_swatches',
        'pantones',
        'materials',
        'suppliers',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

function fabMakeUser(array $permissionNames = ['portal.csr']): \App\Models\User
{
    $user = \App\Models\User::create([
        'name'          => 'Fab ' . uniqid(),
        'username'      => 'fab_' . uniqid(),
        'email'         => 'fab_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['csr'],
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

test('list returns swatches filtered by fabric_type + gsm', function () {
    FabricSwatch::create(['name' => 'Black A',  'fabric_type' => 'CVC',         'gsm' => 240]);
    FabricSwatch::create(['name' => 'Black B',  'fabric_type' => 'CVC',         'gsm' => 280]);
    FabricSwatch::create(['name' => 'White A',  'fabric_type' => '100% Cotton', 'gsm' => 240]);

    $svc = app(FabricSwatchService::class);

    $cvc240 = $svc->list(['fabric_type' => 'CVC', 'gsm' => 240]);
    expect($cvc240)->toHaveCount(1);
    expect($cvc240->first()->name)->toBe('Black A');

    $cvcAll = $svc->list(['fabric_type' => 'CVC']);
    expect($cvcAll)->toHaveCount(2);
});

test('create swatch with valid pantone link returns 201', function () {
    $user = fabMakeUser();
    $this->actingAs($user, 'sanctum');

    $pantone = Pantone::create([
        'name'         => 'Black 6 C',
        'hexcolor'     => '#060606',
        'pantone_code' => 'Black 6 C',
    ]);

    $response = $this->postJson('/api/v2/csr/fabric-swatches', [
        'name'         => 'Jet Black',
        'pantone_id'   => $pantone->id,
        'hex_color'    => '#060606',
        'fabric_type'  => 'CVC',
        'gsm'          => 240,
        'collection'   => '220-240 GSM Neutrals',
        'color_family' => 'Black',
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.name', 'Jet Black');
    $response->assertJsonPath('data.pantone_id', $pantone->id);

    expect(FabricSwatch::count())->toBe(1);
});

test('swatch context surfaces stock status from linked material', function () {
    $matLow   = Materials::create(['name' => 'Cotton Black 240', 'stock_on_hand' => 3]);
    $matOut   = Materials::create(['name' => 'Cotton Black 280', 'stock_on_hand' => 0]);
    $matGood  = Materials::create(['name' => 'Cotton White 240', 'stock_on_hand' => 100]);

    $swatchLow   = FabricSwatch::create(['name' => 'Low Black',   'material_id' => $matLow->id]);
    $swatchOut   = FabricSwatch::create(['name' => 'Out Black',   'material_id' => $matOut->id]);
    $swatchGood  = FabricSwatch::create(['name' => 'Stock White', 'material_id' => $matGood->id]);
    $swatchNone  = FabricSwatch::create(['name' => 'Unlinked']);

    $svc = app(FabricSwatchService::class);

    expect($svc->present($swatchLow->fresh('material'))['stock_status'])->toBe('low_stock');
    expect($svc->present($swatchOut->fresh('material'))['stock_status'])->toBe('out_of_stock');
    expect($svc->present($swatchGood->fresh('material'))['stock_status'])->toBe('in_stock');
    expect($svc->present($swatchNone->fresh('material'))['stock_status'])->toBe('unknown');
});

test('HTTP: GET /csr/fabric-swatches returns 200', function () {
    $user = fabMakeUser();
    $this->actingAs($user, 'sanctum');

    FabricSwatch::create(['name' => 'Jet Black', 'fabric_type' => 'CVC', 'gsm' => 240]);

    $response = $this->getJson('/api/v2/csr/fabric-swatches');

    $response->assertStatus(200);
    $response->assertJsonStructure(['data' => [['id', 'name', 'stock_status']]]);
});

test('recordPick increments pick_count and present() surfaces it', function () {
    $swatch = FabricSwatch::create(['name' => 'Jet Black', 'fabric_type' => 'CVC', 'gsm' => 240]);
    expect((int) $swatch->pick_count)->toBe(0);

    $svc = app(FabricSwatchService::class);

    $afterOne = $svc->recordPick($swatch->id);
    expect((int) $afterOne->pick_count)->toBe(1);

    $afterTwo = $svc->recordPick($swatch->id);
    expect((int) $afterTwo->pick_count)->toBe(2);

    // present() exposes the counter that powers the "Most used" group
    expect($svc->present($afterTwo)['pick_count'])->toBe(2);
});

test('HTTP: POST /csr/fabric-swatches/{id}/pick increments and returns the new count', function () {
    $user = fabMakeUser();
    $this->actingAs($user, 'sanctum');

    $swatch = FabricSwatch::create(['name' => 'Coke Red', 'fabric_type' => 'CVC', 'gsm' => 240]);

    $first = $this->postJson("/api/v2/csr/fabric-swatches/{$swatch->id}/pick");
    $first->assertStatus(200);
    $first->assertJsonPath('data.id', $swatch->id);
    $first->assertJsonPath('data.pick_count', 1);

    $second = $this->postJson("/api/v2/csr/fabric-swatches/{$swatch->id}/pick");
    $second->assertJsonPath('data.pick_count', 2);

    expect((int) FabricSwatch::find($swatch->id)->pick_count)->toBe(2);
});
