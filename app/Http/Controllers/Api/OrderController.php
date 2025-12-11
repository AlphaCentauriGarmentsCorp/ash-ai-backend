<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderStoreRequest;
use App\Http\Requests\Order\OrderUpdateRequest;
use App\Models\Order;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;

class OrderController extends Controller
{
    protected $service;

    public function __construct(OrderService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $order = $this->service->getAll();
        return OrderResource::collection($order);
    }

    public function store(OrderStoreRequest $request)
    {
        $order = $this->service->create($request->validated());
        return new OrderResource($order);
    }

    public function show(Order $order)
    {
        // Route model binding already loaded the model
        return new OrderResource($order);
    }

    public function update(OrderUpdateRequest $request, Order $order)
    {
        // Use the injected model id
        $order = $this->service->update($order->id, $request->validated());
        if (! $order) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new OrderResource($order);
    }

    public function destroy(Order $order)
    {
        // Use the injected model id
        $deleted = $this->service->delete($user->id);
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}