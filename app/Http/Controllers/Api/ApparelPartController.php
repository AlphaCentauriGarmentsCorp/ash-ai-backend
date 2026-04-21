<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApparelPart\Store;
use App\Http\Requests\ApparelPart\Update;
use App\Http\Resources\ApparelPartResource;
use App\Services\ApparelPartService;

class ApparelPartController extends Controller
{
    protected $service;

    public function __construct(ApparelPartService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $apparelParts = $this->service->getAll();

        return ApparelPartResource::collection($apparelParts);
    }

    public function store(Store $request)
    {
        $apparelPart = $this->service->create($request->validated());

        return new ApparelPartResource($apparelPart);
    }

    public function show($id)
    {
        $apparelPart = $this->service->find($id);

        if (! $apparelPart) {
            return response()->json(['message' => 'Apparel part not found'], 404);
        }

        return new ApparelPartResource($apparelPart);
    }

    public function update(Update $request, $id)
    {
        $apparelPart = $this->service->update($request->validated(), $id);

        if (! $apparelPart) {
            return response()->json(['message' => 'Apparel part not found'], 404);
        }

        return new ApparelPartResource($apparelPart);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Apparel part not found'], 404);
        }

        return response()->json(['message' => 'Apparel part deleted successfully']);
    }
}
