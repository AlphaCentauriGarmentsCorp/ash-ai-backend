<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PrintColor\Store;
use App\Http\Requests\PrintColor\Update;
use App\Http\Resources\PrintColorResource;
use App\Services\PrintColorService;

class PrintColorsController extends Controller
{
    protected $service;

    public function __construct(PrintColorService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $printColor = $this->service->getAll();
        return PrintColorResource::collection($printColor);
    }

    public function store(Store $request)
    {
        $printColor = $this->service->create($request->validated());
        return new PrintColorResource($printColor);
    }

    public function show($id)
    {
        $printColor = $this->service->find($id);
        if (! $printColor) {
            return response()->json(['message' => 'Print color not found'], 404);
        }

        return new PrintColorResource($printColor);
    }

    public function update(Update $request, $id)
    {
        $printColor = $this->service->update($request->validated(), $id);
        if (! $printColor) {
            return response()->json(['message' => 'Print color not found'], 404);
        }

        return new PrintColorResource($printColor);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Print color not found'], 404);
        }

        return response()->json(['message' => 'Print color deleted successfully']);
    }
}
