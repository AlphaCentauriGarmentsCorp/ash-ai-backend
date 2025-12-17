<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdersPayment;
use Illuminate\Http\Request;
use App\Services\OrdersPaymentService;
use App\Http\Resources\OrdersPaymentResource;
use App\Http\Requests\Order\OrderStoreRequest;
use App\Http\Requests\Order\OrderUpdateRequest;


class OrdersPaymentController extends Controller
{
    protected $service;

    public function __construct(OrdersPaymentService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $payment = $this->service->getAll();
        return OrdersPaymentResource::collection($payment);
    }

    public function store(OrderStoreRequest $request)
    {
        $payment = $this->service->create($request->validated());
        return new OrdersPaymentResource($payment);
    }

    public function show(OrdersPayment $ordersPayment)
    {
        $payment = $this->service->find($ordersPayment->id);
        if (!$payment) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new OrdersPaymentResource($payment);
    }

    public function update(OrderUpdateRequest $request, OrdersPayment $ordersPayment)
    {
        $payment = $this->service->update($ordersPayment, $request->validated());
        return new OrdersPaymentResource($payment);
    }

    public function destroy(OrdersPayment $ordersPayment)
    {
        $deleted = $this->service->delete($ordersPayment);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}
