<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceType\Store;
use App\Http\Requests\ServiceType\Update;
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

    public function store(Store $request)
    {
        $serviceType = $this->service->create($request->validated());
        return new ServiceTypeResource($serviceType);
    }

    public function show($id)
    {
        $serviceType = $this->service->find($id);
        if (! $serviceType) {
            return response()->json(['message' => 'Service type not found'], 404);
        }
        return new ServiceTypeResource($serviceType);
    }

    public function update(Update $request, $id)
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
