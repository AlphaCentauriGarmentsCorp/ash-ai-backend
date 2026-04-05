<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TshirtNecklineResource;
use App\Services\TshirtNecklineService;
use App\Http\Requests\TshirtNeckline\Store;
use App\Http\Requests\TshirtNeckline\Update;

class TshirtNecklineController extends Controller
{
    protected $service;

    public function __construct(TshirtNecklineService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $screens = $this->service->getAll();
        return TshirtNecklineResource::collection($screens);
    }

    public function store(Store $request)
    {
        $tshirtType = $this->service->create($request->validated());
        return new TshirtNecklineResource($tshirtType);
    }

    public function show($id)
    {
        $tshirtType = $this->service->find($id);
        if (! $tshirtType) {
            return response()->json(['message' => 'Tshirt Type not found'], 404);
        }

        return new TshirtNecklineResource($tshirtType);
    }

    public function update(Update $request, $id)
    {
        $tshirtType = $this->service->update($request->validated(), $id);
        if (! $tshirtType) {
            return response()->json(['message' => 'Tshirt Type not found'], 404);
        }

        return new TshirtNecklineResource($tshirtType);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'tshirtType not found'], 404);
        }
    }
}
