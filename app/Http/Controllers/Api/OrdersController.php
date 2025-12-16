<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use App\Http\Requests\Order\OrderStoreRequest;
use App\Http\Requests\Order\OrderUpdateRequest;

class OrdersController extends Controller
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
        $order = $this->service->find($order->id);
        if (!$order) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new OrderResource($order);
    }

    public function update(OrderUpdateRequest $request, Order $order)
    {
        $order = $this->service->update($order, $request->validated());
        return new OrderResource($order);
    }

    public function destroy(Order $order)
    {
        $deleted = $this->service->delete($order);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}


