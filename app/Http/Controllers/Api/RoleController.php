<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rbac\RoleStoreRequest;
use App\Http\Requests\Rbac\RoleUpdateRequest;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();

        $usersCountMap = DB::table('model_has_roles')
            ->select('role_id', DB::raw('COUNT(*) as users_count'))
            ->groupBy('role_id')
            ->pluck('users_count', 'role_id');

        $roles->each(function (Role $role) use ($usersCountMap): void {
            $role->setAttribute('users_count', (int) ($usersCountMap[$role->id] ?? 0));
        });

        return response()->json(['data' => $roles]);
    }

    public function store(RoleStoreRequest $request)
    {
        $role = Role::create([
            'name' => $request->validated()['name'],
            'guard_name' => 'web',
        ]);

        if ($request->has('permissions')) {
            $role->syncPermissions($request->validated()['permissions']);
        }

        $role->load('permissions:id,name');

        return response()->json([
            'message' => 'Role created successfully',
            'data' => $role,
        ], 201);
    }

    public function show($id)
    {
        $role = Role::query()
            ->with('permissions:id,name')
            ->find($id);

        if (! $role) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $usersCount = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->count();

        $role->setAttribute('users_count', $usersCount);

        return response()->json(['data' => $role]);
    }

    public function update(RoleUpdateRequest $request, $id)
    {
        $role = Role::find($id);

        if (! $role) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $role->name = $validated['name'];
            $role->save();
        }

        if (array_key_exists('permissions', $validated)) {
            $role->syncPermissions($validated['permissions']);
        }

        $role->load('permissions:id,name');

        return response()->json([
            'message' => 'Role updated successfully',
            'data' => $role,
        ]);
    }

    public function destroy($id)
    {
        $role = Role::find($id);

        if (! $role) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (in_array($role->name, ['superadmin', 'admin', 'csr', 'customer'], true)) {
            return response()->json([
                'message' => 'This role is protected and cannot be deleted',
            ], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
