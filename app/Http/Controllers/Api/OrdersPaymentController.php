<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdersPayment;
use Illuminate\Http\Request;
use App\Services\OrdersPaymentService;
use App\Http\Resources\OrdersPaymentResource;
use App\Http\Requests\OrdersPayment\OrdersPaymentStoreRequest;
use App\Http\Requests\OrdersPayment\OrdersPaymentUpdateRequest;


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

    public function store(OrdersPaymentStoreRequest $request)
    {
        $payment = $this->service->create($request->validated());
        return new OrdersPaymentResource($payment);
    }

    public function show(OrdersPayment $orders_payment)
    {
        $payment = $this->service->find($orders_payment->id);
        if (!$payment) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return new OrdersPaymentResource($payment);
    }

    public function update(OrdersPaymentUpdateRequest $request, OrdersPayment $orders_payment)
    {
        $payment = $this->service->update($orders_payment, $request->validated());
        return new OrdersPaymentResource($payment);
    }

    public function destroy(OrdersPayment $orders_payment)
    {
        $deleted = $this->service->delete($orders_payment);
        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}
