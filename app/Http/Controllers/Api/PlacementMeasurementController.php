<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlacementMeasurement\Store;
use App\Http\Requests\PlacementMeasurement\Update;
use App\Http\Resources\PlacementMeasurementResource;
use App\Models\PlacementMeasurement;
use App\Services\PlacementMeasurementService;

class PlacementMeasurementController extends Controller
{
    protected $service;

    public function __construct(PlacementMeasurementService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $placementMeasurements = $this->service->getAll();
        return PlacementMeasurementResource::collection($placementMeasurements);
    }

    public function store(Store $request)
    {
        $placementMeasurement = $this->service->create($request->validated());
        return new PlacementMeasurementResource($placementMeasurement);
    }

    public function show(PlacementMeasurement $placementMeasurement)
    {
        return new PlacementMeasurementResource($placementMeasurement);
    }

    public function update(Update $request, $id)
    {
        $placementMeasurement = $this->service->update($request->validated(), $id);
        if (! $placementMeasurement) {
            return response()->json(['message' => 'Placement measurement not found'], 404);
        }

        return new PlacementMeasurementResource($placementMeasurement);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Placement measurement not found'], 404);
        }

        return response()->json(['message' => 'Placement measurement deleted successfully']);
    }
}
