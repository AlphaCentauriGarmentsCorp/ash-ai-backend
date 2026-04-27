<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rbac\PermissionStoreRequest;
use App\Http\Requests\Rbac\PermissionUpdateRequest;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index()
    {
        $permissions = Permission::query()
            ->withCount('roles')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $permissions]);
    }

    public function store(PermissionStoreRequest $request)
    {
        $permission = Permission::create([
            'name' => $request->validated()['name'],
            'guard_name' => 'web',
        ]);

        return response()->json([
            'message' => 'Permission created successfully',
            'data' => $permission,
        ], 201);
    }

    public function show($id)
    {
        $permission = Permission::query()
            ->with('roles:id,name')
            ->find($id);

        if (! $permission) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $permission]);
    }

    public function update(PermissionUpdateRequest $request, $id)
    {
        $permission = Permission::find($id);

        if (! $permission) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $permission->name = $validated['name'];
            $permission->save();
        }

        return response()->json([
            'message' => 'Permission updated successfully',
            'data' => $permission,
        ]);
    }

    public function destroy($id)
    {
        $permission = Permission::query()->withCount('roles')->find($id);

        if (! $permission) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($permission->roles_count > 0) {
            return response()->json([
                'message' => 'Permission is still assigned to role(s)',
            ], 422);
        }

        $permission->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
