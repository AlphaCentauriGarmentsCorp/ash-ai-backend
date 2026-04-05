<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourierList\Store;
use App\Http\Requests\CourierList\Update;
use App\Http\Resources\CourierListResource;
use App\Models\CourierList;
use App\Services\CourierListService;

class CourierListController extends Controller
{
    protected $service;

    public function __construct(CourierListService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $courierlists = $this->service->getAll();
        return CourierListResource::collection($courierlists);
    }

    public function store(Store $request)
    {
        $courier = $this->service->create($request->validated());
        return new CourierListResource($courier);
    }

    public function show($id)
    {
        $courier = $this->service->find($id);
        if (! $courier) {
            return response()->json(['message' => 'Courier not found'], 404);
        }

        return new CourierListResource($courier);
    }

    public function update(Update $request, $id)
    {
        $courier = $this->service->update($request->validated(), $id);
        if (! $courier) {
            return response()->json(['message' => 'Courier not found'], 404);
        }

        return new CourierListResource($courier);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Courier not found'], 404);
        }

        return response()->json(['message' => 'Courier deleted successfully']);
    }
}
