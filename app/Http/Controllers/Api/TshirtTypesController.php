<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\TshirtTypeService;
use App\Http\Requests\TshirtTypes\Store;
use App\Http\Requests\TshirtTypes\Update;
use App\Http\Resources\TshirtTypeResource;

class TshirtTypesController extends Controller
{
    protected $service;

    public function __construct(TshirtTypeService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $screens = $this->service->getAll();
        return TshirtTypeResource::collection($screens);
    }

    public function store(Store $request)
    {
        $tshirtType = $this->service->create($request->validated());
        return new TshirtTypeResource($tshirtType);
    }

    public function show($id)
    {
        $tshirtType = $this->service->find($id);
        if (! $tshirtType) {
            return response()->json(['message' => 'Tshirt Type not found'], 404);
        }

        return new TshirtTypeResource($tshirtType);
    }

    public function update(Update $request, $id)
    {
        $tshirtType = $this->service->update($request->validated(), $id);
        if (! $tshirtType) {
            return response()->json(['message' => 'Tshirt Type not found'], 404);
        }

        return new TshirtTypeResource($tshirtType);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'tshirtType not found'], 404);
        }
    }
}
