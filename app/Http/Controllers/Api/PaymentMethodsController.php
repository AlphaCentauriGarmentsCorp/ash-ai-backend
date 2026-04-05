<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethods\Store;
use App\Http\Requests\PaymentMethods\Update;
use App\Http\Resources\PaymentMethodsResource;
use App\Models\PaymentMethods;
use App\Services\PaymentMethodsService;

class PaymentMethodsController extends Controller
{
    protected $service;

    public function __construct(PaymentMethodsService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $paymentmethods = $this->service->getAll();
        return PaymentMethodsResource::collection($paymentmethods);
    }

    public function store(Store $request)
    {
        $paymentmethod = $this->service->create($request->validated());
        return new PaymentMethodsResource($paymentmethod);
    }

    public function show($id)
    {
        $paymentmethod = $this->service->find($id);
        if (! $paymentmethod) {
            return response()->json(['message' => 'Payment method not found'], 404);
        }

        return new PaymentMethodsResource($paymentmethod);
    }

    public function update(Update $request, $id)
    {
        $paymentmethod = $this->service->update($request->validated(), $id);
        if (! $paymentmethod) {
            return response()->json(['message' => 'Payment method not found'], 404);
        }

        return new PaymentMethodsResource($paymentmethod);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete($id);

        if (! $deleted) {
            return response()->json(['message' => 'Payment method not found'], 404);
        }

        return response()->json(['message' => 'Payment method deleted successfully']);
    }
}