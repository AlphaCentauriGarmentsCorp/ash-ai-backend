<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderProcesses;
use Illuminate\Http\Request;
use App\Services\OrderProcessesService;
use App\Http\Resources\OrderProcessesResource;
use App\Http\Requests\OrderProcesses\OrderProcessesStoreRequest;
use App\Http\Requests\OrderProcesses\OrderProcessesUpdateRequest;

class OrderProcessesController extends Controller
{
    protected $service;

    public function __construct(OrderProcessesService $service)
    {
        $this->service = $service;
    }


    public function index()
    {
        $process = $this->service->getAll();
        return OrderProcessesResource::collection($process);
    }

    public function store(OrderProcessesStoreRequest $request)
    {
        $process = $this->service->create($request->validated());
        return new OrderProcessesResource($process);
    }

    public function show(OrderProcesses $orderProcesses)
    {
        $process = $this->service->find($orderProcesses->id);
        if (!$process) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new OrderProcessesResource($process);
    }

    public function update(OrderProcessesUpdateRequest $request, OrderProcesses $orderProcesses)
    {
        $process = $this->service->update($orderProcesses, $request->validated());
        return new OrderProcessesResource($process);
    }

    public function destroy(OrderProcesses $orderProcesses)
    {
        $deleted = $this->service->delete($orderProcesses);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}


