<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TshirtSizeResource;
use App\Services\TshirtSizeService;
use App\Http\Requests\TshirtSize\Store;
use App\Http\Requests\TshirtSize\Update;

class TshirtSizeController extends Controller
{
    protected $service;

    public function __construct(TshirtSizeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $tshirtSize = $this->service->getAll();
        return TshirtSizeResource::collection($tshirtSize);
    }

    public function store(Store $request)
    {
        $tshirtSize = $this->service->create($request->validated());
        return new TshirtSizeResource($tshirtSize);
    }

    public function show($id)
    {
        $tshirtSize = $this->service->find($id);
        if (! $tshirtSize) {
            return response()->json(['message' => 'Tshirt size not found'], 404);
        }

        return new TshirtSizeResource($tshirtSize);
    }

    public function update(Update $request, $id)
    {
        $tshirtSize = $this->service->update($request->validated(), $id);
        if (! $tshirtSize) {
            return response()->json(['message' => 'Tshirt size not found'], 404);
        }

        return new TshirtSizeResource($tshirtSize);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Tshirt size not found'], 404);
        }

        return response()->json(['message' => 'Tshirt size deleted successfully']);
    }
}
