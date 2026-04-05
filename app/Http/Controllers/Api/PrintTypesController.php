<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrintTypeResource;
use App\Services\PrintTypeService;
use App\Http\Requests\PrintTypes\Store;
use App\Http\Requests\PrintTypes\Update;


class PrintTypesController extends Controller
{
    protected $service;

    public function __construct(PrintTypeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $printTypes = $this->service->getAll();
        return PrintTypeResource::collection($printTypes);
    }

    public function store(Store $request)
    {
        $printTypes = $this->service->create($request->validated());
        return new PrintTypeResource($printTypes);
    }

    public function show($id)
    {
        $printTypes = $this->service->find($id);
        if (! $printTypes) {
            return response()->json(['message' => 'Print Type not found'], 404);
        }

        return new PrintTypeResource($printTypes);
    }

    public function update(Update $request, $id)
    {
        $printTypes = $this->service->update($request->validated(), $id);
        if (! $printTypes) {
            return response()->json(['message' => 'Print Type not found'], 404);
        }

        return new PrintTypeResource($printTypes);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Print Type not found'], 404);
        }

        return response()->json(['message' => 'Print Type deleted successfully']);
    }
}
