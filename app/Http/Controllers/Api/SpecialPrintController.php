<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpecialPrint\Store;
use App\Http\Requests\SpecialPrint\Update;
use App\Http\Resources\SpecialPrintResource;
use App\Models\SpecialPrint;
use App\Services\SpecialPrintService;

class SpecialPrintController extends Controller
{
    protected $service;

    public function __construct(SpecialPrintService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $specialPrints = $this->service->getAll();
        return SpecialPrintResource::collection($specialPrints);
    }

    public function store(Store $request)
    {
        $specialPrint = $this->service->create($request->validated());
        return new SpecialPrintResource($specialPrint);
    }

    public function show($id)
    {
        $specialPrint = $this->service->find($id);
        if (! $specialPrint) {
            return response()->json(['message' => 'Special print not found'], 404);
        }
        return new SpecialPrintResource($specialPrint);
    }

    public function update(Update $request, $id)
    {
        $specialPrint = $this->service->update($request->validated(), $id);
        if (! $specialPrint) {
            return response()->json(['message' => 'Special print not found'], 404);
        }

        return new SpecialPrintResource($specialPrint);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Special print not found'], 404);
        }

        return response()->json(['message' => 'Special print deleted successfully']);
    }
}
