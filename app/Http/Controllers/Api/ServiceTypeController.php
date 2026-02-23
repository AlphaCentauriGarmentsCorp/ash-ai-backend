<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceType\ServiceTypeStoreRequest;
use App\Http\Requests\ServiceType\ServiceTypeUpdateRequest;
use App\Http\Resources\ServiceTypeResource;
use App\Models\ServiceType;
use App\Services\ServiceTypeService;

class ServiceTypeController extends Controller
{
    protected $service;

    public function __construct(ServiceTypeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $serviceTypes = $this->service->getAll();
        return ServiceTypeResource::collection($serviceTypes);
    }

    public function store(ServiceTypeStoreRequest $request)
    {
        $serviceType = $this->service->create($request->validated());
        return new ServiceTypeResource($serviceType);
    }

    public function show(ServiceType $serviceType)
    {
        return new ServiceTypeResource($serviceType);
    }

    public function update(ServiceTypeUpdateRequest $request, $id)
    {
        $serviceType = $this->service->update($request->validated(), $id);
        if (! $serviceType) {
            return response()->json(['message' => 'Service type not found'], 404);
        }

        return new ServiceTypeResource($serviceType);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Service type not found'], 404);
        }

        return response()->json(['message' => 'Service type deleted successfully']);
    }
}
