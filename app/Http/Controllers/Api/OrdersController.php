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
use App\Models\OrderStage;
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
        $orders = Order::with(['apparelType', 'patternType', 'printMethod', 'currentStage', 'assignedCsr'])
            ->withCount([
                'payments as verified_payments_count' => fn ($q) =>
                    $q->where('status', OrderPayment::STATUS_VERIFIED),
                'orderStages as total_stages_count',
                'orderStages as completed_stages_count' => fn ($q) =>
                    $q->where('status', OrderStage::STATUS_COMPLETED),
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

        // Resolve the shared label-design artwork: an uploaded file
        // (label_design_file) stored to the public disk, OR a text link/path
        // (label_design_path). One upload covers both Brand + Care/Size labels.
        // Mirrors QuotationController::store. Only set when the request actually
        // carried one, so nothing is clobbered when none is provided.
        $validated = $request->validated();
        $resolvedLabelDesign = $this->resolveLabelDesign($request);
        if ($resolvedLabelDesign !== null) {
            $validated['label_design_path'] = $resolvedLabelDesign;
        }

        $order = $this->service->store($validated, [
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

        // Same shared label-design resolution as store() (see resolveLabelDesign).
        // On edit, when no new file/link is sent, the frontend echoes the existing
        // label_design_path so it is preserved; if it doesn't, buildOrderAttributes
        // omits the column and the current value is kept.
        $validated = $request->validated();
        $resolvedLabelDesign = $this->resolveLabelDesign($request);
        if ($resolvedLabelDesign !== null) {
            $validated['label_design_path'] = $resolvedLabelDesign;
        }

        $order = $this->service->update($order, $validated, [
            'override'          => $wantsOverride,
            'incomplete_fields' => (array) $request->input('incomplete_fields', []),
            'actor'             => $actor,
        ]);

        return new OrderResource($order);
    }

    /**
     * Resolve the shared label-design artwork from the request. Accepts either
     * an uploaded file (`label_design_file`) which is stored to the public disk,
     * or a text link/path (`label_design_path`). One upload is shared between the
     * Brand Label and the Care/Size Label. Returns the resolved string, or null
     * when the request carries neither (so callers can leave an existing value
     * untouched on edit). Mirrors QuotationController::resolveLabelDesign, but
     * stores under an order-scoped folder.
     */
    protected function resolveLabelDesign(\Illuminate\Http\Request $request): ?string
    {
        if ($request->hasFile('label_design_file')) {
            $file = $request->file('label_design_file');
            if ($file && $file->isValid()) {
                return $file->store('order-label-designs', 'public');
            }
        }

        $link = $request->input('label_design_path');
        if (is_string($link) && trim($link) !== '') {
            return trim($link);
        }

        return null;
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

    /**
     * List soft-deleted (trashed) orders.
     *
     * Powers the "Show deleted" toggle on the All Orders page. Mirrors
     * index() exactly — same eager-loads, same withCount aliases, same
     * OrderResource — but scoped to onlyTrashed(), so the frontend can reuse
     * the identical columns and row shape. Ordered newest-deleted first.
     * deleted_at is surfaced by OrderResource so the UI can show when each
     * order was removed.
     */
    public function deletedIndex()
    {
        $orders = Order::onlyTrashed()
            ->with(['apparelType', 'patternType', 'printMethod', 'currentStage', 'assignedCsr'])
            ->withCount([
                'payments as verified_payments_count' => fn ($q) =>
                    $q->where('status', OrderPayment::STATUS_VERIFIED),
                'orderStages as total_stages_count',
                'orderStages as completed_stages_count' => fn ($q) =>
                    $q->where('status', OrderStage::STATUS_COMPLETED),
            ])
            ->orderByDesc('deleted_at')
            ->get();

        return OrderResource::collection($orders);
    }

    /**
     * Restore a soft-deleted order (clears deleted_at).
     *
     * Mirrors the employee restore flow (AccountService::restore). Scoped to
     * onlyTrashed() so restoring a live order is a clean 404 rather than a
     * silent no-op, and a bad id 404s. The order re-appears in index() /
     * withActiveStage() / show() immediately, back in its prior workflow
     * state (nothing about the record changed except deleted_at).
     */
    public function restore($id)
    {
        $order = Order::onlyTrashed()->find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found in trash'], 404);
        }

        $order->restore();

        return response()->json([
            'message' => 'Order restored.',
            'id'      => (int) $id,
        ]);
    }

    /**
     * PERMANENTLY delete a soft-deleted order (hard delete, irreversible).
     *
     * Guarded to onlyTrashed(): an order must ALREADY be in the trash before
     * it can be force-deleted. You cannot hard-delete a live order in one
     * step — this prevents accidental irreversible loss straight from the
     * All Orders list (soft-delete first, then permanently delete from the
     * "Show deleted" view).
     *
     * Cascade is handled by the database: every child table's order_id FK is
     * cascadeOnDelete() (po_items, order_stages, order_designs, order_payments,
     * client_approvals, all stage_* logs, order_packing_boxes, ...), so
     * forceDelete() removes all related production history with no orphans.
     * tickets.order_id and csr_activity_logs.order_id are nullOnDelete, so
     * those records survive with the link cleared.
     *
     * CAVEAT (PO numbers): generatePoCode() uses Order::withTrashed() so a
     * soft-deleted order keeps "occupying" its PO number and it is never
     * reused. A permanently deleted order no longer occupies its number — so
     * hard-deleting the CURRENT YEAR'S highest-numbered order lets the next
     * new order reclaim that PO code. Deleting any non-latest order has no
     * effect on the sequence.
     */
    public function forceDestroy($id)
    {
        $order = Order::onlyTrashed()->find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found in trash'], 404);
        }

        $order->forceDelete();

        return response()->json([
            'message' => 'Order permanently deleted.',
            'id'      => (int) $id,
        ]);
    }
}