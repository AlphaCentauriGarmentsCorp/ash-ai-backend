<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SizePrices\Store;
use App\Http\Requests\SizePrices\Update;
use App\Http\Resources\SizePricesResource;
use App\Services\SizePricesService;

class SizePricesController extends Controller
{
    protected $service;

    public function __construct(SizePricesService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $sizePrices = $this->service->getAll();
        return SizePricesResource::collection($sizePrices);
    }

    public function store(Store $request)
    {
        $sizePrices = $this->service->create($request->validated());
        return new SizePricesResource($sizePrices);
    }

    public function show($id)
    {
        $sizePrices = $this->service->find($id);
        if (! $sizePrices) {
            return response()->json(['message' => 'Size price not found'], 404);
        }

        return new SizePricesResource($sizePrices);
    }

    public function update(Update $request, $id)
    {
        $sizePrices = $this->service->update($request->validated(), $id);
        if (! $sizePrices) {
            return response()->json(['message' => 'Size price not found'], 404);
        }

        return new SizePricesResource($sizePrices);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Size price not found'], 404);
        }

        return response()->json(['message' => 'Size price deleted successfully']);
    }
}
