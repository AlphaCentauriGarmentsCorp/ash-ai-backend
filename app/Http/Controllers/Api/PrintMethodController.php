<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PrintMethod\PrintMethodStoreRequest;
use App\Http\Requests\PrintMethod\PrintMethodUpdateRequest;
use App\Http\Resources\PrintMethodResource;
use App\Models\PrintMethod;
use App\Services\PrintMethodService;

class PrintMethodController extends Controller
{
    protected $service;

    public function __construct(PrintMethodService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $printMethods = $this->service->getAll();
        return PrintMethodResource::collection($printMethods);
    }

    public function store(PrintMethodStoreRequest $request)
    {
        $printMethod = $this->service->create($request->validated());
        return new PrintMethodResource($printMethod);
    }

    public function show(PrintMethod $printMethod, $id)
    {
        $printMethod = $this->service->find($id);
        if (! $printMethod) {
            return response()->json(['message' => 'Print method not found'], 404);
        }
        return new PrintMethodResource($printMethod);
    }

    public function update(PrintMethodUpdateRequest $request, $id)
    {
        $printMethod = $this->service->update($request->validated(), $id);
        if (! $printMethod) {
            return response()->json(['message' => 'Print method not found'], 404);
        }

        return new PrintMethodResource($printMethod);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Print method not found'], 404);
        }

        return response()->json(['message' => 'Print method deleted successfully']);
    }
}
