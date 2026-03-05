<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EquipmentInventory\Store;
use App\Http\Requests\EquipmentInventory\Update;
use App\Services\EquipmentInventoryService;
use App\Http\Resources\EquipmentInventoryResource;
use App\Models\EquipmentInventory;

class EquipmentInventoryController extends Controller
{
    protected $service;

    public function __construct(EquipmentInventoryService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $equipmentInventory = $this->service->getAll();
        return EquipmentInventoryResource::collection($equipmentInventory);
    }

    public function getByLocation($id)
    {
        $equipmentInventory = $this->service->getByLocation($id);
        return EquipmentInventoryResource::collection($equipmentInventory);
    }

    public function show($id)
    {
        $equipmentInventory = $this->service->find($id);
        if (! $equipmentInventory) {
            return response()->json(['message' => 'Equipment not found'], 404);
        }
        return new EquipmentInventoryResource($equipmentInventory);
    }

    public function store(Store $request)
    {
        $equipmentInventory = $this->service->create($request->validated());
        if (! $equipmentInventory) {
            return response()->json(['message' => 'Failed to create equipment inventory'], 404);
        }
        return new EquipmentInventoryResource($equipmentInventory);
    }

    public function update(Update $request, $id)
    {
        $equipmentInventory = $this->service->update($request->validated(), $id);
        if (!$equipmentInventory) {
            return response()->json(['message' => 'Equipment not found'], 404);
        }

        return new EquipmentInventoryResource($equipmentInventory);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Equipment not found'], 404);
        }

        return response()->json(['message' => 'Equipment deleted successfully']);
    }
}
