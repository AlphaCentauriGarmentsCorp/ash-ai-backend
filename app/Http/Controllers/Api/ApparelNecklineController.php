<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApparelNeckline\Store;
use App\Http\Requests\ApparelNeckline\Update;
use App\Http\Resources\ApparelNecklineResource;
use App\Services\ApparelNecklineService;

class ApparelNecklineController extends Controller
{
    protected $service;

    public function __construct(ApparelNecklineService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $apparelNecklines = $this->service->getAll();

        return ApparelNecklineResource::collection($apparelNecklines);
    }

    public function store(Store $request)
    {
        $apparelNeckline = $this->service->create($request->validated());

        return new ApparelNecklineResource($apparelNeckline);
    }

    public function show($id)
    {
        $apparelNeckline = $this->service->find($id);

        if (! $apparelNeckline) {
            return response()->json(['message' => 'Apparel neckline not found'], 404);
        }

        return new ApparelNecklineResource($apparelNeckline);
    }

    public function update(Update $request, $id)
    {
        $apparelNeckline = $this->service->update($request->validated(), $id);

        if (! $apparelNeckline) {
            return response()->json(['message' => 'Apparel neckline not found'], 404);
        }

        return new ApparelNecklineResource($apparelNeckline);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Apparel neckline not found'], 404);
        }

        return response()->json(['message' => 'Apparel neckline deleted successfully']);
    }
}
