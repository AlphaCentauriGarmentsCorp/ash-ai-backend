<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Materials\Store;
use App\Http\Requests\Materials\Update;
use App\Services\MaterialsService;
use App\Http\Resources\MaterialResource;

class MaterialsController extends Controller
{
    protected $service;

    public function __construct(MaterialsService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $materials = $this->service->getAll();
        return MaterialResource::collection($materials);
    }

    public function getBySupplier($id)
    {
        $materials = $this->service->getBySupplier($id);
        return MaterialResource::collection($materials);
    }

    public function getByType($type)
    {
        $materials = $this->service->getByType($type);
        return MaterialResource::collection($materials);
    }

    public function store(Store $request)
    {
        $materials = $this->service->create($request->validated());
        return new MaterialResource($materials);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Material not found'], 404);
        }

        return response()->json(['message' => 'Material deleted successfully']);
    }
}
