<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Design\DesignStoreRequest;
use App\Http\Requests\Design\DesignUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Design;
use App\Services\DesignService;
use App\Http\Resources\DesignResource;

class DesignController extends Controller
{
    protected $service;

    public function __construct(DesignService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $designs = $this->service->getAll();
        return DesignResource::collection($designs);
    }

    public function store(DesignStoreRequest $request)
    {
        $design = $this->service->create($request->validated());
        return new DesignResource($design);
    }

    public function show(Design $design)
    {
        // Route model binding already loaded the model
        return new DesignResource($design);
    }

    public function update(DesignUpdateRequest $request, Design $design)
    {
        // Use the injected model's id
        $design = $this->service->update($design->id, $request->validated());
        if (! $design) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new DesignResource($design);
    }

    public function destroy(Design $design)
    {
        // Use the injected model's id
        $deleted = $this->service->delete($design->id);
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}