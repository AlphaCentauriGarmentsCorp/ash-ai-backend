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

        // Grant the requested permissions to the role(s) as well, so the auth
        // payload's roles[].permissions (built from $role->permissions) reflects
        // them — this mirrors how RbacSeeder attaches permissions to roles in
        // production, where the admin role carries its permission set.
        if ($permissionNames !== []) {
            foreach ($roleNames as $roleName) {
                Role::where('name', $roleName)->where('guard_name', 'web')
                    ->first()?->givePermissionTo($permissionNames);
            }
        }
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

    expect($response->json('user.roles'))->toBeArray();
    expect($response->json('user.roles.0.name'))->toBe('admin');
    expect($response->json('user.roles.0.permissions'))->toContain('access.rbac');
    expect($response->json('user.permissions'))->toContain('access.rbac');
    expect($response->json('user.all_permissions'))->toContain('access.rbac');
    expect($response->json('user.permission_names.access.rbac'))->toBeTrue();
});

it('returns permissions and role metadata from me', function () {
    $user = makeAshUser(['admin'], ['access.rbac']);

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/v2/me');

    $response->assertOk()
        ->assertJsonStructure([
            'id',
            'name',
            'email',
            'avatar',
            'roles' => [
                ['id', 'name', 'guard_name', 'permissions'],
            ],
            'role_names',
            'permissions',
            'all_permissions',
            'permission_names',
            'domain_role',
            'domain_access',
            'created_at',
            'updated_at',
        ]);

    expect($response->json('permissions'))->toContain('access.rbac');
    expect($response->json('roles.0.name'))->toBe('admin');
    expect($response->json('roles.0.permissions'))->toContain('access.rbac');
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