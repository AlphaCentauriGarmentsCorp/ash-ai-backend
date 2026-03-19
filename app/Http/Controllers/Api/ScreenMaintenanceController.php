<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScreenMaintenance\Store;
use App\Http\Requests\ScreenMaintenance\Update;
use App\Services\ScreenMaintenanceService;
use App\Http\Resources\ScreenMaintenanceResource;

class ScreenMaintenanceController extends Controller
{
    protected $service;

    public function __construct(ScreenMaintenanceService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $maintenance = $this->service->getAll();
        return ScreenMaintenanceResource::collection($maintenance);
    }

    public function show($id)
    {
        $maintenance = $this->service->find($id);
        if (!$maintenance) {
            return response()->json(['message' => 'Screen maintenance not found'], 404);
        }
        return new ScreenMaintenanceResource($maintenance);
    }

    public function getByUser($id)
    {
        $maintenance = $this->service->getByUserId($id);
        return ScreenMaintenanceResource::collection($maintenance);
    }

    public function store(Store $request)
    {
        $maintenance = $this->service->create($request->validated());
        return new ScreenMaintenanceResource($maintenance);
    }

    public function update(Update $request, $id)
    {
        $maintenance = $this->service->update($request->validated(), $id);

        if (!$maintenance) {
            return response()->json(['message' => 'Screen maintenance not found'], 404);
        }

        return new ScreenMaintenanceResource($maintenance);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (!$deleted) {
            return response()->json(['message' => 'Screen maintenance not found'], 404);
        }

        return response()->json(['message' => 'Screen maintenance deleted successfully']);
    }
}
