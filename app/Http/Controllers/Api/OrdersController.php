<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Services\OrderService;
use App\Http\Resources\OrderResource;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\OrderUpdateRequest;
use App\Models\OrderPayment;
use App\Exceptions\BusinessRuleException;

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
        // verified_payments_count powers OrderResource::is_editable without an
        // N+1 across the list (an order with a verified payment is in production
        // and no longer editable).
        // Eager-load apparel/pattern/print relations so the list resource
        // exposes apparel_type, pattern_type and print_method (whenLoaded).
        // Without this they were absent and the list rendered "—".
        $orders = Order::with(['apparelType', 'patternType', 'printMethod'])
            ->withCount([
                'payments as verified_payments_count' => fn ($q) =>
                    $q->where('status', OrderPayment::STATUS_VERIFIED),
            ])->get();

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
        $actor = $request->user();
        $wantsOverride = $request->boolean('override_incomplete');

        // Change 11 — only a superadmin may save an order that is missing
        // soft-required fields. Gating on the ROLE (not a permission) so it
        // works regardless of the superadmin permission set, and matches the
        // spec exactly. Non-superadmins get a specific business error rather
        // than a silent strip of the flag.
        if ($wantsOverride && ! ($actor && $actor->hasRole('superadmin'))) {
            throw new BusinessRuleException(
                'Only a superadmin can save an order with missing details.',
                'ORDER_OVERRIDE_FORBIDDEN',
                403,
            );
        }

        $order = $this->service->store($request->validated(), [
            'override'          => $wantsOverride,
            'incomplete_fields' => (array) $request->input('incomplete_fields', []),
            'actor'             => $actor,
        ]);

        return new OrderResource($order);
    }

    /**
     * Edit an existing order (Issue 1). Same superadmin-only override gate as
     * store(); additionally refuses once the order has entered production (a
     * verified payment exists) so PO-item SKUs / printed labels can't churn.
     */
    public function update(OrderUpdateRequest $request, $id)
    {
        $order = Order::findOrFail($id);
        $actor = $request->user();
        $wantsOverride = $request->boolean('override_incomplete');

        if ($wantsOverride && ! ($actor && $actor->hasRole('superadmin'))) {
            throw new BusinessRuleException(
                'Only a superadmin can save an order with missing details.',
                'ORDER_OVERRIDE_FORBIDDEN',
                403,
            );
        }

        // Editability boundary: once a payment is verified the order has been
        // approved into production and can no longer be edited.
        $inProduction = $order->payments()
            ->where('status', OrderPayment::STATUS_VERIFIED)
            ->exists();
        if ($inProduction) {
            throw new BusinessRuleException(
                'This order has already entered production and can no longer be edited.',
                'ORDER_LOCKED_FOR_EDIT',
                422,
            );
        }

        $order = $this->service->update($order, $request->validated(), [
            'override'          => $wantsOverride,
            'incomplete_fields' => (array) $request->input('incomplete_fields', []),
            'actor'             => $actor,
        ]);

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