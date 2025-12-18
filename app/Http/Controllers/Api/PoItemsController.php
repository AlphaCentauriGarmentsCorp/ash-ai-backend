<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PoItems\PoItemsStoreRequest;
use App\Http\Requests\PoItems\PoItemsUpdateRequest;
use App\Http\Resources\PoItemsResource;
use App\Services\PoItemsService;
use Illuminate\Http\Request;

class PoItemsController extends Controller
{
    protected PoItemsService $poItemsService;

    public function __construct(PoItemsService $poItemsService)
    {
        $this->poItemsService = $poItemsService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $items = $this->poItemsService->getAll();
        return PoItemsResource::collection($items);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PoItemsStoreRequest $request)
    {
        $item = $this->poItemsService->create($request->validated());
        return new PoItemsResource($item);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        $item = $this->poItemsService->find($id);

        if (! $item) {
            return response()->json(['message' => 'PO Item not found'], 404);
        }

        return new PoItemsResource($item);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PoItemsUpdateRequest $request, int $id)
    {
        $item = $this->poItemsService->update($id, $request->validated());

        if (! $item) {
            return response()->json(['message' => 'PO Item not found'], 404);
        }

        return new PoItemsResource($item);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        $deleted = $this->poItemsService->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'PO Item not found'], 404);
        }

        return response()->json(['message' => 'PO Item deleted successfully']);
    }
}