<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarehouseMaterials;
use Illuminate\Http\Request;
use App\Services\WarehouseMaterialsService;
use App\Http\Resources\WarehouseMaterialResource;
use App\Http\Requests\WarehouseMaterial\WarehouseMaterialStoreRequest;
use App\Http\Requests\WarehouseMaterial\WarehouseMaterialUpdateRequest;


class WarehouseMaterialsController extends Controller
{
    protected $service;

    public function __construct(WarehouseMaterialsService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $warehouseMaterials = $this->service->getAll();
        return WarehouseMaterialResource::collection($warehouseMaterials);
    }

    public function store(WarehouseMaterialStoreRequest $request)
    {
        $warehouseMaterial = $this->service->create($request->validated());
        return new WarehouseMaterialResource($warehouseMaterial);
    }

    public function show(WarehouseMaterials $warehouseMaterial)
    {
        return new WarehouseMaterialResource($warehouseMaterial);
    }

    

    public function update(
        WarehouseMaterialUpdateRequest $request,
        WarehouseMaterials $warehouseMaterial
    ) {
        return new WarehouseMaterialResource(
            $this->service->update($warehouseMaterial, $request->validated())
        );
    }

    public function destroy(WarehouseMaterials $warehouseMaterial)
    {
        $deleted = $this->service->delete($warehouseMaterial);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}
