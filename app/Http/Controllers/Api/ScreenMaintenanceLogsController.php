<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScreenMaintenanceLogs\Store;
use App\Services\ScreenMaintenanceLogsService;
use App\Http\Resources\ScreenMaintenanceLogsResource;

class ScreenMaintenanceLogsController extends Controller
{
    protected $service;

    public function __construct(ScreenMaintenanceLogsService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $maintenanceLogs = $this->service->getAll();

        return ScreenMaintenanceLogsResource::collection($maintenanceLogs);
    }

    public function show($id)
    {
        $maintenanceLog = $this->service->find($id);

        if (!$maintenanceLog) {
            return response()->json(['message' => 'Screen maintenance log not found'], 404);
        }

        return new ScreenMaintenanceLogsResource($maintenanceLog);
    }

    public function getLogsByScreen($id)
    {
        $maintenanceLogs = $this->service->getByScreenId($id);

        return ScreenMaintenanceLogsResource::collection($maintenanceLogs);
    }

    public function store(Store $request)
    {
        $maintenanceLog = $this->service->create($request->validated());

        return new ScreenMaintenanceLogsResource($maintenanceLog);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (!$deleted) {
            return response()->json(['message' => 'Screen maintenance log not found'], 404);
        }

        return response()->json(['message' => 'Screen maintenance log deleted successfully']);
    }
}
