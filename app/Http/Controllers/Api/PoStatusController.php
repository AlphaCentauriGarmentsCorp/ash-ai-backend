<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PoStatus\PoStatusStoreRequest;
use App\Http\Requests\PoStatus\PoStatusUpdateRequest;
use App\Models\PoStatus;
use App\Services\PoStatusService;
use App\Http\Resources\PoStatusResource;

class PoStatusController extends Controller
{
    protected $service;

    public function __construct(PoStatusService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $poStatuses = $this->service->getAll();
        return PoStatusResource::collection($poStatuses);
    }

    public function store(PoStatusStoreRequest $request)
    {
        $poStatus = $this->service->create($request->validated());
        return new PoStatusResource($poStatus);
    }

    public function show(PoStatus $poStatus)
    {
        // Route model binding already loaded the model
        return new PoStatusResource($poStatus);
    }

    public function update(PoStatusUpdateRequest $request, PoStatus $poStatus)
    {
        // Use the injected model id
        $poStatus = $this->service->update($poStatus->id, $request->validated());
        if (! $poStatus) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new PoStatusResource($poStatus);
    }

    public function destroy(PoStatus $poStatus)
    {
        // Use the injected model id
        $deleted = $this->service->delete($poStatus->id);
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}