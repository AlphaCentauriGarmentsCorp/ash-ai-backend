<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Freebie\FreebieStoreRequest;
use App\Http\Requests\Freebie\FreebieUpdateRequest;
use App\Http\Resources\FreebieResource;
use App\Models\Freebie;
use App\Services\FreebieService;

class FreebieController extends Controller
{
    protected $service;

    public function __construct(FreebieService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $freebies = $this->service->getAll();
        return FreebieResource::collection($freebies);
    }

    public function store(FreebieStoreRequest $request)
    {
        $freebie = $this->service->create($request->validated());
        return new FreebieResource($freebie);
    }

    public function show(Freebie $freebie)
    {
        return new FreebieResource($freebie);
    }

    public function update(FreebieUpdateRequest $request, $id)
    {
        $freebie = $this->service->update($request->validated(), $id);
        if (! $freebie) {
            return response()->json(['message' => 'Freebie not found'], 404);
        }

        return new FreebieResource($freebie);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Freebie not found'], 404);
        }

        return response()->json(['message' => 'Freebie deleted successfully']);
    }
}
