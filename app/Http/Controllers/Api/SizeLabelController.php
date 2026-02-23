<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SizeLabel\SizeLabelStoreRequest;
use App\Http\Requests\SizeLabel\SizeLabelUpdateRequest;
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

    public function store(SizeLabelStoreRequest $request)
    {
        $sizeLabel = $this->service->create($request->validated());
        return new SizeLabelResource($sizeLabel);
    }

    public function show(SizeLabel $sizeLabel)
    {
        return new SizeLabelResource($sizeLabel);
    }

    public function update(SizeLabelUpdateRequest $request, $id)
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
