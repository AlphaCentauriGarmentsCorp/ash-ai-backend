<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SizeLabel\Store;
use App\Http\Requests\SizeLabel\Update;
use App\Http\Resources\SizeLabelResource;
use App\Models\SizeLabel;
use App\Services\SizeLabelService;

class SizeLabelController extends Controller
{
    protected $service;

    public function __construct(SizeLabelService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $sizeLabels = $this->service->getAll();
        return SizeLabelResource::collection($sizeLabels);
    }

    public function store(Store $request)
    {
        $sizeLabel = $this->service->create($request->validated());
        return new SizeLabelResource($sizeLabel);
    }

    public function show(SizeLabel $sizeLabel, $id)
    {
        $sizeLabel = $this->service->find($id);
        if (! $sizeLabel) {
            return response()->json(['message' => 'Size label not found'], 404);
        }
        return new SizeLabelResource($sizeLabel);
    }

    public function update(Update $request, $id)
    {
        $sizeLabel = $this->service->update($request->validated(), $id);
        if (! $sizeLabel) {
            return response()->json(['message' => 'Size label not found'], 404);
        }

        return new SizeLabelResource($sizeLabel);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Size label not found'], 404);
        }

        return response()->json(['message' => 'Size label deleted successfully']);
    }
}
