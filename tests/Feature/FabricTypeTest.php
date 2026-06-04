<?php

use App\Models\FabricType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Change 7.1 — Fabric Type managed dropdown (mirrors service-type).
 * CRUD works for users with access.dropdown-settings; others are 403.
 */
beforeEach(function () {
    foreach ([
        'model_has_permissions', 'role_has_permissions', 'model_has_roles',
        'permissions', 'roles', 'fabric_types', 'users',
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

    Schema::create('fabric_types', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->text('description')->nullable();
        $t->timestamps();
    });

    Schema::create('roles', function (Blueprint $t) {
        $t->id(); $t->string('name'); $t->string('guard_name')->default('web'); $t->timestamps();
    });
    Schema::create('permissions', function (Blueprint $t) {
        $t->id(); $t->string('name'); $t->string('guard_name')->default('web'); $t->timestamps();
    });
    Schema::create('model_has_roles', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id'); $t->string('model_type'); $t->unsignedBigInteger('model_id');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });
    Schema::create('model_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id'); $t->string('model_type'); $t->unsignedBigInteger('model_id');
        $t->primary(['permission_id', 'model_id', 'model_type']);
    });
    Schema::create('role_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id'); $t->unsignedBigInteger('role_id');
        $t->primary(['permission_id', 'role_id']);
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function fabricMakeUser(array $perms = []): \App\Models\User
{
    $u = \App\Models\User::create([
        'name'          => 'U ' . uniqid(),
        'username'      => 'u_' . uniqid(),
        'email'         => 'u_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['superadmin'],
    ]);
    foreach ($perms as $p) {
        Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        $u->givePermissionTo($p);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    return $u;
}

test('a manager can list fabric types', function () {
    FabricType::create(['name' => 'CVC']);
    FabricType::create(['name' => '100% Cotton']);

    $this->actingAs(fabricMakeUser(['access.dropdown-settings']), 'sanctum');
    $this->getJson('/api/v2/fabric-type')
        ->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'name', 'description']]]);
});

test('a manager can create a fabric type', function () {
    $this->actingAs(fabricMakeUser(['access.dropdown-settings']), 'sanctum');
    $this->postJson('/api/v2/fabric-type', ['name' => 'Dri-Fit'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Dri-Fit');

    expect(FabricType::where('name', 'Dri-Fit')->exists())->toBeTrue();
});

test('a manager can update and delete a fabric type', function () {
    $ft = FabricType::create(['name' => 'Combed 24s']);
    $this->actingAs(fabricMakeUser(['access.dropdown-settings']), 'sanctum');

    $this->putJson("/api/v2/fabric-type/{$ft->id}", ['name' => 'Combed Cotton 24s'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Combed Cotton 24s');

    $this->deleteJson("/api/v2/fabric-type/{$ft->id}")->assertSuccessful();
    expect(FabricType::find($ft->id))->toBeNull();
});

test('a user without access.dropdown-settings is rejected (403)', function () {
    $this->actingAs(fabricMakeUser([]), 'sanctum');
    $this->getJson('/api/v2/fabric-type')->assertStatus(403);
    $this->postJson('/api/v2/fabric-type', ['name' => 'X'])->assertStatus(403);
});

test('name is required', function () {
    $this->actingAs(fabricMakeUser(['access.dropdown-settings']), 'sanctum');
    $this->postJson('/api/v2/fabric-type', ['name' => ''])->assertStatus(422);
});