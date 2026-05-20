<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use App\Http\Requests\Order\StoreOrderRequest;
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
        $orders = Order::get();

        return OrderResource::collection($orders);
    }

    /**
     * Phase 3 — Lightweight order list for the Material Request order picker.
     *
     * Returns only orders that currently have an active workflow stage
     * (i.e., not yet completed and not cancelled), with a minimal
     * payload: id, po_code, client_brand, client_name, current stage label.
     * Used by the New Material Request form.
     */
    public function withActiveStage()
    {
        $orders = Order::query()
            ->whereNotNull('current_stage_id')
            ->whereNotIn('workflow_status', ['completed', 'cancelled'])
            ->with(['currentStage:id,stage,sequence,status'])
            ->orderByDesc('id')
            ->get(['id', 'po_code', 'client_brand', 'client_name', 'workflow_status', 'current_stage_id']);

        return response()->json([
            'data' => $orders->map(fn ($o) => [
                'id'              => $o->id,
                'po_code'         => $o->po_code,
                'client_brand'    => $o->client_brand,
                'client_name'     => $o->client_name,
                'workflow_status' => $o->workflow_status,
                'current_stage'   => $o->currentStage ? [
                    'id'       => $o->currentStage->id,
                    'stage'    => $o->currentStage->stage,
                    'sequence' => $o->currentStage->sequence,
                    'status'   => $o->currentStage->status,
                ] : null,
            ]),
        ]);
    }


    public function show($po_code)
    {
        $order = Order::with([
            'client',
            'items',
            'orderStages' => fn ($q) => $q->orderBy('sequence'),
            'orderDesign.placements',
            'screenAssignment.screen',
            'screenChecking.items',
            'samples',
        ])->where('po_code', $po_code)->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }
        return new OrderResource($order);
    }


    public function store(StoreOrderRequest $request)
    {
        $order = $this->service->store($request->validated());

        return new OrderResource($order);
    }
}
