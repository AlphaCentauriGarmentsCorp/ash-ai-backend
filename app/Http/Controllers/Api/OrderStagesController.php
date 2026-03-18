<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderStages\Store;
use App\Services\OrderStagesService;
use App\Http\Resources\OrderStageResource;

class OrderStagesController extends Controller
{

    protected $service;

    public function __construct(OrderStagesService $service)
    {
        $this->service = $service;
    }

    public function store(Store $request)
    {
        $orders = $this->service->create($request->validated());

        return OrderStageResource::collection($orders);
    }
}
