<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Design\DesignStoreRequest;
use App\Http\Requests\Design\DesignUpdateRequest;
use App\Http\Resources\DesignResource;
use App\Services\DesignService;
use Illuminate\Http\JsonResponse;

class DesignController extends Controller
{
    protected DesignService $designService;

    public function __construct(DesignService $designService)
    {
        $this->designService = $designService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $designs = $this->designService->getAll();
        return response()->json(DesignResource::collection($designs));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(DesignStoreRequest $request): JsonResponse
    {
        $design = $this->designService->create($request->validated());
        return response()->json(new DesignResource($design), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id): JsonResponse
    {
        $design = $this->designService->find($id);

        if (! $design) {
            return response()->json(['message' => 'Design not found'], 404);
        }

        return response()->json(new DesignResource($design));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(DesignUpdateRequest $request, int $id): JsonResponse
    {
        $design = $this->designService->update($id, $request->validated());

        if (! $design) {
            return response()->json(['message' => 'Design not found'], 404);
        }

        return response()->json(new DesignResource($design));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->designService->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Design not found'], 404);
        }

        return response()->json(['message' => 'Design deleted successfully']);
    }
}