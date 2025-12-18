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

    public function show(OrderProcesses $order_process)
    {
        $order_process = $this->service->find($order_process->id);
        if (!$order_process) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new OrderProcessesResource($order_process);
    }

    public function update(OrderProcessesUpdateRequest $request, OrderProcesses $order_process)
    {
        $process = $this->service->update($order_process, $request->validated());
        return new OrderProcessesResource($process);
    }

    public function destroy(OrderProcesses $order_process)
    {
        $deleted = $this->service->delete($order_process);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}


