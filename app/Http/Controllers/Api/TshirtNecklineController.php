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
        $tshirtNeckline = $this->service->getAll();
        return TshirtNecklineResource::collection($tshirtNeckline);
    }

    public function store(Store $request)
    {
        $tshirtNeckline = $this->service->create($request->validated());
        return new TshirtNecklineResource($tshirtNeckline);
    }

    public function show($id)
    {
        $tshirtNeckline = $this->service->find($id);
        if (! $tshirtNeckline) {
            return response()->json(['message' => 'Tshirt Neckline not found'], 404);
        }

        return new TshirtNecklineResource($tshirtNeckline);
    }

    public function update(Update $request, $id)
    {
        $tshirtNeckline = $this->service->update($request->validated(), $id);
        if (! $tshirtNeckline) {
            return response()->json(['message' => 'Tshirt Neckline not found'], 404);
        }

        return new TshirtNecklineResource($tshirtNeckline);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Tshirt Neckline not found'], 404);
        }

        return response()->json(['message' => 'Tshirt Neckline deleted successfully']);
    }
}
