<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SewingSubcontractor\Store;
use App\Http\Requests\SewingSubcontractor\Update;
use App\Http\Resources\SewingSubcontractorResource;
use App\Models\SewingSubcontractor;
use App\Services\SewingSubcontractorService;

class SewingSubcontractorController extends Controller
{
    protected $service;

    public function __construct(SewingSubcontractorService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $subcontractors = $this->service->getAll();
        return SewingSubcontractorResource::collection($subcontractors);
    }

    public function store(Store $request)
    {
        $subcontractor = $this->service->create($request->validated());
        return new SewingSubcontractorResource($subcontractor);
    }

    public function show($id)
    {
        $subcontractor = $this->service->find($id);
        if (!$subcontractor) {
            return response()->json(['message' => 'Sewing subcontractor not found'], 404);
        }

        return new SewingSubcontractorResource($subcontractor);
    }

    public function update(Update $request, $id)
    {
        $subcontractor = $this->service->update($request->validated(), $id);
        if (!$subcontractor) {
            return response()->json(['message' => 'Sewing subcontractor not found'], 404);
        }

        return new SewingSubcontractorResource($subcontractor);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (!$deleted) {
            return response()->json(['message' => 'Sewing subcontractor not found'], 404);
        }

        return response()->json(['message' => 'Sewing subcontractor deleted successfully']);
    }
}
