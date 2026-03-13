<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdditionalOption\Store;
use App\Http\Requests\AdditionalOption\Update;
use App\Http\Resources\AdditionalOptionResource;
use App\Models\AdditionalOption;
use App\Services\AdditionalOptionService;

class AdditionalOptionController extends Controller
{
    protected $service;

    public function __construct(AdditionalOptionService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $additionalOptions = $this->service->getAll();
        return AdditionalOptionResource::collection($additionalOptions);
    }

    public function store(Store $request)
    {
        $additionalOption = $this->service->create($request->validated());
        return new AdditionalOptionResource($additionalOption);
    }

    public function show($id)
    {
        $additionalOption = $this->service->find($id);
        if (! $additionalOption) {
            return response()->json(['message' => 'Additional option not found'], 404);
        }

        return new AdditionalOptionResource($additionalOption);
    }

    public function update(Update $request, $id)
    {
        $additionalOption = $this->service->update($request->validated(), $id);
        if (! $additionalOption) {
            return response()->json(['message' => 'Additional option not found'], 404);
        }

        return new AdditionalOptionResource($additionalOption);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Additional option not found'], 404);
        }

        return response()->json(['message' => 'Additional option deleted successfully']);
    }
}
