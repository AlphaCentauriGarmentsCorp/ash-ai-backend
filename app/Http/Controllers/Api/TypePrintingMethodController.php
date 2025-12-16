<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\TypePrintingMethod;
use App\Services\TypePrintingMethodService;
use App\Http\Resources\TypePrintingMethodResource;
use App\Http\Requests\TypePrintingMethod\TypePrintingMethodStoreRequest;
use App\Http\Requests\TypePrintingMethod\TypePrintingMethodUpdateRequest;


class TypePrintingMethodController extends Controller
{
    protected $service;

    public function __construct(TypePrintingMethodService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $typePrintingMethods = $this->service->getAll();
        return TypePrintingMethodResource::collection($typePrintingMethods);
    }

    public function store(TypePrintingMethodStoreRequest $request)
    {
        $typePrintingMethod = $this->service->create($request->validated());
        return new TypePrintingMethodResource($typePrintingMethod);
    }

    public function show($id)
    {
        $typePrintingMethod = $this->service->find($id);
        if (!$typePrintingMethod) {
            return response()->json(['message' => 'Type Printing Method not found'], 404);
        }
        return new TypePrintingMethodResource($typePrintingMethod);
    }

    public function update(TypePrintingMethodUpdateRequest $request, TypePrintingMethod $typePrintingMethod)
    {
        $typePrintingMethod = $this->service->update($typePrintingMethod, $request->validated());
        return new TypePrintingMethodResource($typePrintingMethod);
    }

    public function destroy(TypePrintingMethod $typePrintingMethod)
    {
        $deleted = $this->service->delete($typePrintingMethod);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}
