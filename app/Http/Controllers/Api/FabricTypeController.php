<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FabricType\Store;
use App\Http\Requests\FabricType\Update;
use App\Http\Resources\FabricTypeResource;
use App\Services\FabricTypeService;

class FabricTypeController extends Controller
{
    protected $service;

    public function __construct(FabricTypeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return FabricTypeResource::collection($this->service->getAll());
    }

    public function store(Store $request)
    {
        $fabricType = $this->service->create($request->validated());
        return new FabricTypeResource($fabricType);
    }

    public function show($id)
    {
        $fabricType = $this->service->find($id);
        if (! $fabricType) {
            return response()->json(['message' => 'Fabric type not found'], 404);
        }
        return new FabricTypeResource($fabricType);
    }

    public function update(Update $request, $id)
    {
        $fabricType = $this->service->update($request->validated(), $id);
        if (! $fabricType) {
            return response()->json(['message' => 'Fabric type not found'], 404);
        }
        return new FabricTypeResource($fabricType);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);
        if (! $deleted) {
            return response()->json(['message' => 'Fabric type not found'], 404);
        }
        return response()->json(['message' => 'Fabric type deleted successfully']);
    }
}
