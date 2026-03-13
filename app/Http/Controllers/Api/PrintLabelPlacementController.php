<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PrintLabelPlacement\Store;
use App\Http\Requests\PrintLabelPlacement\Update;
use App\Http\Resources\PrintLabelPlacementResource;
use App\Models\PrintLabelPlacement;
use App\Services\PrintLabelPlacementService;

class PrintLabelPlacementController extends Controller
{
    protected $service;

    public function __construct(PrintLabelPlacementService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $printLabelPlacements = $this->service->getAll();
        return PrintLabelPlacementResource::collection($printLabelPlacements);
    }

    public function store(Store $request)
    {
        $printLabelPlacement = $this->service->create($request->validated());
        return new PrintLabelPlacementResource($printLabelPlacement);
    }

    public function show(PrintLabelPlacement $printLabelPlacement, $id)
    {
        $printLabelPlacement = $this->service->find($id);
        if (! $printLabelPlacement) {
            return response()->json(['message' => 'Print label placement not found'], 404);
        }
        return new PrintLabelPlacementResource($printLabelPlacement);
    }

    public function update(Update $request, $id)
    {
        $printLabelPlacement = $this->service->update($request->validated(), $id);
        if (! $printLabelPlacement) {
            return response()->json(['message' => 'Print label placement not found'], 404);
        }

        return new PrintLabelPlacementResource($printLabelPlacement);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Print label placement not found'], 404);
        }

        return response()->json(['message' => 'Print label placement deleted successfully']);
    }
}
