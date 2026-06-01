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

    /**
     * Live price preview for the Add Order form (Option A pricing).
     *
     * Delegates to the SAME pricing engine the Quotation form uses
     * (QuotationService::preview → normalizePayload), so an order priced from
     * scratch follows the exact Addendum rules and can never disagree with a
     * quotation built from the same inputs. Computes only — no DB writes.
     *
     * Accepts the same payload shape as the quotation preview:
     * item_config_json / items_json / print_parts_json / addons_json /
     * apparel_neckline_id / discount_*.
     */
    public function pricePreview(Request $request, \App\Services\QuotationService $quotation)
    {
        $totals = $quotation->preview($request->all());

        return response()->json($totals);
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
            'apparelType',
            'patternType',
            'printMethod',
            'apparelNeckline',
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

    /**
     * Soft-delete an order.
     *
     * Sets deleted_at (via the Order model's SoftDeletes trait) rather than
     * hard-deleting, so the order and all ~27 tables of related production
     * history remain intact and recoverable. The order immediately drops out
     * of index / withActiveStage / show because those use Eloquent, which
     * excludes trashed records automatically.
     *
     * NOTE (policy hook): this currently allows deleting an order at ANY
     * stage. If you later want to PREVENT deleting orders that are already
     * in production (e.g. past sample_approval), add a guard here that
     * inspects $order->workflow_status and returns 422 before deleting.
     */
    public function destroy($id)
    {
        $order = Order::find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $order->delete(); // soft delete — sets deleted_at

        return response()->json([
            'message' => 'Order deleted.',
            'id'      => (int) $id,
        ]);
    }
}