<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\FabricType\FabricTypeStoreRequest;
use App\Http\Requests\FabricType\FabricTypeUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\FabricType;
use App\Services\FabricTypeService;
use App\Http\Resources\FabricTypeResource;

class FabricTypeController extends Controller
{
    protected $service;

    public function __construct(FabricTypeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $fabricTypes = $this->service->getAll();
        return FabricTypeResource::collection($fabricTypes);
    }

    public function store(FabricTypeStoreRequest $request)
    {
        $fabricType = $this->service->create($request->validated());
        return new FabricTypeResource($fabricType);
    }

    public function show(FabricType $fabricType)
    {
        return new FabricTypeResource($fabricType);
    }

    public function update(FabricTypeUpdateRequest $request, FabricType $fabricType)
    {
        // Use the injected model's id
        $fabricType = $this->service->update($fabricType->id, $request->validated());
        if (! $fabricType) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new FabricTypeResource($fabricType);
    }

    public function destroy(FabricType $fabricType)
    {
        $deleted = $this->service->delete($fabricType->id);
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}