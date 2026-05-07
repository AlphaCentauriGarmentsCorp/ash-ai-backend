<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderStageResource;
use App\Models\Order;
use App\Models\OrderStage;
use App\Services\OrderStagesService;
use App\Support\WorkflowStages;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * REST surface for the sequential stage workflow.
 *
 * Endpoints:
 *   GET    /v2/order-stages/workflow                – workflow definition
 *   GET    /v2/order-stages/order/{orderId}         – list stages for an order
 *   POST   /v2/order-stages/{id}/complete           – mark stage completed
 *   POST   /v2/order-stages/{id}/for-approval       – mark stage for_approval
 *   POST   /v2/order-stages/{id}/delay              – mark stage delayed
 *   POST   /v2/order-stages/{id}/hold               – mark stage on_hold
 *   POST   /v2/order-stages/{id}/resume             – resume on_hold/delayed
 *   POST   /v2/order-stages/{id}/assign             – assign to a user
 *   POST   /v2/order-stages/{id}/notes              – attach a note
 *   POST   /v2/order-stages                         – LEGACY no-op (kept for FE compat)
 */
class OrderStagesController extends Controller
{
    protected OrderStagesService $service;

    public function __construct(OrderStagesService $service)
    {
        $this->service = $service;
    }

    /**
     * Returns the canonical workflow definition.
     * Useful for the frontend to render labels/icons consistently with the
     * backend's source of truth.
     */
    public function workflow()
    {
        return response()->json([
            'data' => WorkflowStages::all(),
        ]);
    }

    /**
     * Returns the (sequenced) list of stages for the given order.
     */
    public function indexForOrder(int $orderId)
    {
        $order = Order::findOrFail($orderId);

        $stages = OrderStage::where('order_id', $order->id)
            ->orderBy('sequence')
            ->get();

        return OrderStageResource::collection($stages);
    }

    /**
     * Legacy endpoint preserved for the existing OrderStage.jsx checkbox UI
     * during the migration window. New behavior:
     *
     *  - If the order has no stages yet, auto-initialize them all.
     *  - Otherwise it is a no-op (returns the current stages).
     *
     * The legacy "select which stages to track" model is no longer
     * supported – every order gets the full 14-stage workflow.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::findOrFail($data['order_id']);

        if ($order->orderStages()->count() === 0) {
            $this->service->initializeForOrder($order);
        }

        $stages = OrderStage::where('order_id', $order->id)
            ->orderBy('sequence')
            ->get();

        return OrderStageResource::collection($stages);
    }

    public function complete(Request $request, int $id)
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $next = $this->service->markComplete($id, $data['notes'] ?? null);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Cannot complete stage',
                'errors'  => $e->errors(),
            ], 422);
        }

        $current = OrderStage::find($id)?->fresh();

        return response()->json([
            'message' => 'Stage marked completed.',
            'stage'   => $current ? new OrderStageResource($current) : null,
            'next'    => $next ? new OrderStageResource($next) : null,
        ]);
    }

    public function forApproval(Request $request, int $id)
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $stage = $this->service->markForApproval($id, $data['notes'] ?? null);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Cannot mark for approval',
                'errors'  => $e->errors(),
            ], 422);
        }

        return new OrderStageResource($stage);
    }

    public function delay(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $stage = $this->service->markDelayed($id, $data['reason']);

        return new OrderStageResource($stage);
    }

    public function hold(Request $request, int $id)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $stage = $this->service->markOnHold($id, $data['reason'] ?? null);

        return new OrderStageResource($stage);
    }

    public function resume(int $id)
    {
        try {
            $stage = $this->service->resume($id);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Cannot resume stage',
                'errors'  => $e->errors(),
            ], 422);
        }

        return new OrderStageResource($stage);
    }

    public function assign(Request $request, int $id)
    {
        $data = $request->validate([
            'assigned_to'   => 'nullable|integer|exists:users,id',
            'assigned_role' => 'nullable|string|max:64',
        ]);

        $stage = $this->service->assign(
            $id,
            $data['assigned_to'] ?? null,
            $data['assigned_role'] ?? null
        );

        return new OrderStageResource($stage);
    }

    public function note(Request $request, int $id)
    {
        $data = $request->validate([
            'notes' => 'required|string|max:2000',
        ]);

        $stage = OrderStage::findOrFail($id);
        $stage->update(['notes' => $data['notes']]);

        return new OrderStageResource($stage->fresh());
    }
}
