<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingMethods\Store;
use App\Http\Requests\ShippingMethods\Update;
use App\Http\Resources\ShippingMethodResource;
use App\Services\ShippingMethodService;

class ShippingMethodController extends Controller
{
    protected $service;

    public function __construct(ShippingMethodService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $shippingMethods = $this->service->getAll();
        return ShippingMethodResource::collection($shippingMethods);
    }

    public function store(Store $request)
    {
        $shippingMethod = $this->service->create($request->validated());
        return new ShippingMethodResource($shippingMethod);
    }

    public function show($id)
    {
        $shippingMethod = $this->service->find($id);

        if (! $shippingMethod) {
            return response()->json(['message' => 'Shipping method not found'], 404);
        }

        return new ShippingMethodResource($shippingMethod);
    }

    public function update(Update $request, $id)
    {
        $shippingMethod = $this->service->update($request->validated(), $id);

        if (! $shippingMethod) {
            return response()->json(['message' => 'Shipping method not found'], 404);
        }

        return new ShippingMethodResource($shippingMethod);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Shipping method not found'], 404);
        }

        return response()->json(['message' => 'Shipping method deleted successfully']);
    }
}