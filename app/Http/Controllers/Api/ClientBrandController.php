<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ClientBrand\ClientBrandStoreRequest;
use App\Http\Requests\ClientBrand\ClientBrandUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\ClientBrand;
use App\Services\ClientBrandService;
use App\Http\Resources\ClientBrandResource;

class ClientBrandController extends Controller
{
    protected $service;

    public function __construct(ClientBrandService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $clientBrand = $this->service->getAll();
        return ClientBrandResource::collection($clientBrand);
    }

    public function store(ClientBrandStoreRequest $request)
    {
        $clientBrand = $this->service->create($request->validated());
        return new ClientBrandResource($clientBrand);
    }

    public function show(ClientBrand $clientBrand)
    {
        // Route model binding already loaded the model
        return new ClientBrandResource($clientBrand);
    }

    public function update(ClientBrandUpdateRequest $request, ClientBrand $clientBrand)
    {
        // Use the injected model's id
        $clientBrand = $this->service->update($clientBrand->id, $request->validated());
        if (! $clientBrand) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new ClientBrandResource($clientBrand);
    }

    public function destroy(ClientBrand $clientBrand)
    {
        // Use the injected model's id
        $deleted = $this->service->delete($clientBrand->id);
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}