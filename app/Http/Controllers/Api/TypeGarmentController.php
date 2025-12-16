<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TypeGarment;
use Illuminate\Http\Request;
use App\Services\TypeGarmentService;
use App\Http\Resources\TypeGarmentResource;
use App\Http\Requests\TypeGarment\TypeGarmentStoreRequest;
use App\Http\Requests\TypeGarment\TypeGarmentUpdateRequest;

class TypeGarmentController extends Controller
{
    protected $service;

    public function __construct(TypeGarmentService $service)
    {
        $this->service = $service;
    }


    public function index()
    {
        $typeGarments = $this->service->getAll();
        return TypeGarmentResource::collection($typeGarments);
    }

    public function store(TypeGarmentStoreRequest $request)
    {
        $typeGarment = $this->service->create($request->validated());
        return new TypeGarmentResource($typeGarment);
    }

    public function show($id)
    {
        $typeGarment = $this->service->find($id);
        if (!$typeGarment) {
            return response()->json(['message' => 'Type Garment not found'], 404);
        }
        return new TypeGarmentResource($typeGarment);
    }

    public function update(TypeGarmentUpdateRequest $request, TypeGarment $typeGarment)
    {
        $typeGarment = $this->service->update($typeGarment, $request->validated());
        return new TypeGarmentResource($typeGarment);
    }

    public function destroy(TypeGarment $typeGarment)
    {
        $deleted = $this->service->delete($typeGarment);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}
