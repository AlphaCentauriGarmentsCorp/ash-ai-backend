<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function makeAshUser(array $roleNames = ['admin'], array $permissionNames = []): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($roleNames as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }

    foreach ($permissionNames as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
    }

    $user = User::factory()->create([
        'username' => 'ash_' . uniqid(),
        'domain_role' => $roleNames,
        'domain_access' => ['ash'],
    ]);

    if ($roleNames !== []) {
        $user->syncRoles($roleNames);
    }

    if ($permissionNames !== []) {
        $user->givePermissionTo($permissionNames);
    }

    return $user;
}

it('includes roles and permissions in the auth payload', function () {
    $user = makeAshUser(['admin'], ['access.rbac']);

    $response = $this->postJson('/api/v2/login/ash', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'user' => [
                'id',
                'name',
                'email',
                'avatar',
                'roles',
                'permissions',
                'domain_role',
                'domain_access',
            ],
            'token',
        ]);

    expect($response->json('user.roles'))->toContain('admin');
    expect($response->json('user.permissions'))->toContain('access.rbac');
});

it('forbids access to permission-protected routes without the permission', function () {
    $user = makeAshUser(['admin']);

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/v2/rbac/roles');

    $response->assertForbidden();
});

it('allows access to permission-protected routes with the permission', function () {
    $user = makeAshUser(['admin'], ['access.rbac']);

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/v2/rbac/roles');

    $response->assertOk()
        ->assertJsonStructure([
            'data',
        ]);
});