<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddonsResource;
use App\Services\AddonsService;
use App\Http\Requests\Addons\Store;
use App\Http\Requests\Addons\Update;

class AddonsController extends Controller
{
    protected $service;

    public function __construct(AddonsService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $addons = $this->service->getAll();
        return AddonsResource::collection($addons);
    }

    public function store(Store $request)
    {
        $addons = $this->service->create($request->validated());
        return new AddonsResource($addons);
    }

    public function show($id)
    {
        $addons = $this->service->find($id);
        if (! $addons) {
            return response()->json(['message' => 'Addons not found'], 404);
        }

        return new AddonsResource($addons);
    }

    public function update(Update $request, $id)
    {
        $addons = $this->service->update($request->validated(), $id);
        if (! $addons) {
            return response()->json(['message' => 'Addons not found'], 404);
        }

        return new AddonsResource($addons);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Addons not found'], 404);
        }

        return response()->json(['message' => 'Addons deleted successfully']);
    }
}
