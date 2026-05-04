<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use App\Http\Requests\Order\StoreOrderRequest;

class OrdersController extends Controller
{
    protected $service;

    public function __construct(OrderService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $orders = Order::with('client')->latest()->get();
        return OrderResource::collection($orders);
    }

    public function show($po_code)
    {
        $order = Order::with([
            'client',
            'quotation',
            'items',
            'orderStages',
            'orderDesign.placements',
            'screenAssignment.screen',
            'screenChecking.items',
            'samples',
            'tickets',
        ])->where('po_code', $po_code)->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        return new OrderResource($order);
    }

    public function store(StoreOrderRequest $request)
    {
        $order = $this->service->store($request->validated(), $request);
        return (new OrderResource($order))->response()->setStatusCode(201);
    }
}
