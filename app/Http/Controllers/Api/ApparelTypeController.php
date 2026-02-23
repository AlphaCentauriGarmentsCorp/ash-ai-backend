<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApparelType\ApparelTypeStoreRequest;
use App\Http\Requests\ApparelType\ApparelTypeUpdateRequest;
use App\Http\Resources\ApparelTypeResource;
use App\Models\ApparelType;
use App\Services\ApparelTypeService;

class ApparelTypeController extends Controller
{
    protected $service;

    public function __construct(ApparelTypeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $apparelTypes = $this->service->getAll();
        return ApparelTypeResource::collection($apparelTypes);
    }

    public function store(ApparelTypeStoreRequest $request)
    {
        $apparelType = $this->service->create($request->validated());
        return new ApparelTypeResource($apparelType);
    }

    public function show(ApparelType $apparelType)
    {
        return new ApparelTypeResource($apparelType);
    }

    public function update(ApparelTypeUpdateRequest $request, $id)
    {
        $apparelType = $this->service->update($request->validated(), $id);
        if (! $apparelType) {
            return response()->json(['message' => 'Apparel type not found'], 404);
        }

        return new ApparelTypeResource($apparelType);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Apparel type not found'], 404);
        }

        return response()->json(['message' => 'Apparel type deleted successfully']);
    }
}
