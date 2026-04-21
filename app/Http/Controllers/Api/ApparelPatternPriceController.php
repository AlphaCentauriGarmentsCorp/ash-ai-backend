<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApparelPatternPriceResource;
use App\Services\ApparelPatternPriceService;
use App\Http\Requests\ApparelPatternPrice\Store;
use App\Http\Requests\ApparelPatternPrice\Update;

class ApparelPatternPriceController extends Controller
{
    protected $service;

    public function __construct(ApparelPatternPriceService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $prices = $this->service->getAll();
        return ApparelPatternPriceResource::collection($prices);
    }

    public function store(Store $request)
    {
        $price = $this->service->create($request->validated());
        return new ApparelPatternPriceResource($price);
    }

    public function show($id)
    {
        $price = $this->service->find($id);
        if (!$price) {
            return response()->json(['message' => 'Apparel Pattern Price not found'], 404);
        }

        return new ApparelPatternPriceResource($price);
    }

    public function update(Update $request, $id)
    {
        $price = $this->service->update($request->validated(), $id);
        if (!$price) {
            return response()->json(['message' => 'Apparel Pattern Price not found'], 404);
        }

        return new ApparelPatternPriceResource($price);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (!$deleted) {
            return response()->json(['message' => 'Apparel Pattern Price not found'], 404);
        }

        return response()->json(['message' => 'Apparel Pattern Price deleted successfully']);
    }

    public function getPrice($apparelTypeName, $patternTypeName)
    {
        $price = $this->service->getPrice($apparelTypeName, $patternTypeName);
        
        if ($price === null) {
            return response()->json(['message' => 'Price combination not found'], 404);
        }

        return response()->json(['price' => $price]);
    }
}
