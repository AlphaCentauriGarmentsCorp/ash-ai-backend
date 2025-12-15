<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TypeSize;
use Illuminate\Http\Request;
use App\Services\TypeSizeService;
use App\Http\Resources\TypeSizeResource;
use App\Http\Requests\TypeSize\TypeSizeStoreRequest;
use App\Http\Requests\TypeSize\TypeSizeUpdateRequest;

class TypeSizeController extends Controller
{
    protected $service;

    public function __construct(TypeSizeService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $typeSizes = $this->service->getAll();
        return TypeSizeResource::collection($typeSizes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(TypeSizeStoreRequest $request)
    {
        $typeSize = $this->service->create($request->validated());
        return new TypeSizeResource($typeSize);
    }

    /**
     * Display the specified resource.
     */
    public function show(TypeSize $typeSize)
    {
        return new TypeSizeResource($typeSize);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(TypeSizeUpdateRequest $request, TypeSize $typeSize)
    {
        // Use the injected model's id
        $typeSize = $this->service->update($typeSize->id, $request->validated());
        if (! $typeSize) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new TypeSizeResource($typeSize);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TypeSize $typeSize)
    {
        $deleted = $this->service->delete($typeSize->id);
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}
