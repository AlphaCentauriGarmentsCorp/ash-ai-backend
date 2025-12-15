<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\User\UserStoreRequest;
use App\Http\Requests\User\UserUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserService;
use App\Http\Resources\UserResource;

class UserController extends Controller
{
    protected $service;

    public function __construct(UserService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $user = $this->service->getAll();
        return UserResource::collection($user);
    }

    public function store(UserStoreRequest $request)
    {
        $user = $this->service->create($request->validated());
        return new UserResource($user);
    }

    public function show(User $user)
    {
        // Route model binding already loaded the model
        return new UserResource($user);
    }

    public function update(UserUpdateRequest $request, User $user)
    {
        // Use the injected model's id
        $user = $this->service->update($user->id, $request->validated());
        if (! $user) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new UserResource($user);
    }

    public function destroy(User $user)
    {
        // Use the injected model's id
        $deleted = $this->service->delete($user->id);
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}