<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EquipmentLocation\StoreLocation;
use App\Http\Requests\EquipmentLocation\UpdateLocation;
use App\Models\EquipmentLocation;
use Illuminate\Http\Request;
use App\Services\EquipmentLocationService;
use App\Http\Resources\EquipmentLocationResource;


class EquipmentLocationController extends Controller
{
    protected $service;

    public function __construct(EquipmentLocationService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $equipmentLocations = $this->service->getAll();
        return EquipmentLocationResource::collection($equipmentLocations);
    }

    public function store(StoreLocation $request)
    {
        $equipmentLocation = $this->service->create($request->validated());
        if (! $equipmentLocation) {
            return response()->json(['message' => 'Failed to create equipment location'], 404);
        }
        return new EquipmentLocationResource($equipmentLocation);
    }

    public function show(EquipmentLocation $equipmentLocation, $id)
    {
        $equipmentLocation = $this->service->find($id);
        if (! $equipmentLocation) {
            return response()->json(['message' => 'Equipment location not found'], 404);
        }
        return new EquipmentLocationResource($equipmentLocation);
    }

    public function update(UpdateLocation $request, $id)
    {
        $equipmentLocation = $this->service->update($request->validated(), $id);
        if (! $equipmentLocation) {
            return response()->json(['message' => 'Equipment location not found'], 404);
        }

        return new EquipmentLocationResource($equipmentLocation);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Equipment location not found'], 404);
        }

        return response()->json(['message' => 'Equipment location deleted successfully']);
    }
}
