<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PatternType\PatternTypeStoreRequest;
use App\Http\Requests\PatternType\PatternTypeUpdateRequest;
use App\Http\Resources\PatternTypeResource;
use App\Models\PatternType;
use App\Services\PatternTypeService;

class PatternTypeController extends Controller
{
    protected $service;

    public function __construct(PatternTypeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $patternTypes = $this->service->getAll();
        return PatternTypeResource::collection($patternTypes);
    }

    public function store(PatternTypeStoreRequest $request)
    {
        $patternType = $this->service->create($request->validated());
        return new PatternTypeResource($patternType);
    }

    public function show(PatternType $patternType)
    {
        return new PatternTypeResource($patternType);
    }

    public function update(PatternTypeUpdateRequest $request, $id)
    {
        $patternType = $this->service->update($request->validated(), $id);
        if (! $patternType) {
            return response()->json(['message' => 'Pattern type not found'], 404);
        }

        return new PatternTypeResource($patternType);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Pattern type not found'], 404);
        }

        return response()->json(['message' => 'Pattern type deleted successfully']);
    }
}
