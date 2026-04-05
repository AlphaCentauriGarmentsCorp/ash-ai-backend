<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrintPatternResource;
use App\Services\PrintPatternService;
use App\Http\Requests\PrintPattern\Store;
use App\Http\Requests\PrintPattern\Update;


class PrintPatternController extends Controller
{
    protected $service;

    public function __construct(PrintPatternService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $PrintPattern = $this->service->getAll();
        return PrintPatternResource::collection($PrintPattern);
    }

    public function store(Store $request)
    {
        $PrintPattern = $this->service->create($request->validated());
        return new PrintPatternResource($PrintPattern);
    }

    public function show($id)
    {
        $PrintPattern = $this->service->find($id);
        if (! $PrintPattern) {
            return response()->json(['message' => 'Print pattern not found'], 404);
        }

        return new PrintPatternResource($PrintPattern);
    }

    public function update(Update $request, $id)
    {
        $PrintPattern = $this->service->update($request->validated(), $id);
        if (! $PrintPattern) {
            return response()->json(['message' => 'Print pattern not found'], 404);
        }

        return new PrintPatternResource($PrintPattern);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Print pattern not found'], 404);
        }

        return response()->json(['message' => 'Print pattern deleted successfully']);
    }
}
