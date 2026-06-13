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
    // NOTE: ->json() uses dot notation, which cannot address a key that
    // CONTAINS a dot ('access.rbac' is one literal key, not nested) — the
    // old assertion returned null by construction. Fetch the map and check
    // the literal key instead (Pest's toHaveKey has the same dot trap).
    $permissionNames = $response->json('user.permission_names');
    expect($permissionNames)->toBeArray();
    expect(array_key_exists('access.rbac', $permissionNames))->toBeTrue();
    expect($permissionNames['access.rbac'])->toBeTrue();
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
// ---------------------------------------------------------------------------
// Issue 16 (portal side) — AccountService must keep Spatie roles in lockstep
// with domain_role, so permission-gated portals follow ALL assigned roles.
// Before the fix, only AuthService::register synced Spatie; Add/Edit User
// wrote domain_role JSON and silently left Spatie stale.
// ---------------------------------------------------------------------------

it('syncs Spatie roles for ALL assigned roles when an account is updated', function () {
    $user = makeAshUser(['cutter']);

    app(\App\Services\AccountService::class)->update($user->id, [
        'roles' => ['cutter', 'printer'],
    ]);

    $user->refresh();

    // domain_role keeps the ordered array (index 0 = primary by convention).
    expect($user->domain_role)->toBe(['cutter', 'printer']);

    // Spatie now carries BOTH roles — the secondary's portal permissions
    // (via RbacSeeder role→permission mapping) follow automatically.
    expect($user->hasRole('cutter'))->toBeTrue();
    expect($user->hasRole('printer'))->toBeTrue();
});

it('assigns Spatie roles to accounts created via the Add User flow', function () {
    $user = app(\App\Services\AccountService::class)->create([
        'first_name'     => 'Multi',
        'last_name'      => 'Role',
        'contact_number' => '09170000000',
        'gender'         => 'other',
        'civil_status'   => 'single',
        'birthdate'      => '1990-01-01',
        'position'       => 'Operator',
        'department'     => 'Production',
        'username'       => 'multi_' . uniqid(),
        'email'          => uniqid('multi_') . '@test.local',
        'password'       => 'password',
        'roles'          => ['sewer', 'qa'],
    ]);

    // Before the fix, users created here had domain_role but ZERO Spatie
    // roles, so every permission-gated route 403'd for them.
    expect($user->hasRole('sewer'))->toBeTrue();
    expect($user->hasRole('qa'))->toBeTrue();
    expect($user->domain_role)->toBe(['sewer', 'qa']);
});
