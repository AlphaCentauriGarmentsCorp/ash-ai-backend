<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaterialPrep\AssignSupplier;
use App\Http\Requests\MaterialPrep\QuickAddSupplier;
use App\Http\Requests\MaterialPrep\SaveMaterialRequirement;
use App\Http\Resources\SupplierResource;
use App\Models\Order;
use App\Services\MaterialPrepPortalService;
use App\Services\MaterialPrepRequirementService;
use App\Services\OrderStagesService;
use App\Services\PortalAssignmentService;
use App\Services\SupplierService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-G — Material Preparation Portal endpoints.
 *
 * Endpoints:
 *   GET  /api/v2/portal/material-prep/my-active       — resolve active PRs
 *   GET  /api/v2/portal/material-prep/context/{prId}  — full PR context
 *   PATCH /api/v2/portal/material-prep/{prId}/supplier — assign/change supplier (pending only)
 *
 * Other PR actions (mark-ordered, mark-received, cancel, approve)
 * route through the existing /api/v2/purchase-requests/* endpoints,
 * which are already gated by the appropriate permissions.
 *
 * Gated by portal.material-prep permission.
 */
class MaterialPrepPortalController extends Controller
{
    public function __construct(
        protected MaterialPrepPortalService $service,
        protected MaterialPrepRequirementService $requirements,
    ) {
    }

    public function myActive(Request $request)
    {
        return response()->json(
            $this->service->myActiveRequests($request->user())
        );
    }

    public function showContext(int $prId)
    {
        $payload = $this->service->buildContext($prId);
        return response()->json(['data' => $payload]);
    }

    public function assignSupplier(AssignSupplier $request, int $prId)
    {
        $pr = $this->service->assignSupplier(
            $prId,
            (int) $request->validated()['supplier_id'],
            $request->user(),
        );

        return response()->json([
            'data' => [
                'id'           => $pr->id,
                'pr_code'      => $pr->pr_code,
                'status'       => $pr->status,
                'supplier_id'  => $pr->supplier_id,
                'supplier'     => $pr->supplier ? [
                    'id'             => $pr->supplier->id,
                    'name'           => $pr->supplier->name,
                    'order_channels' => $pr->supplier->order_channels ?? [],
                    'is_incomplete'  => (bool) $pr->supplier->is_incomplete,
                ] : null,
            ],
        ]);
    }

    /**
     * Issue 20 — inline quick-add a supplier from the PR picker.
     * Saved to the shared Material Suppliers table, flagged incomplete.
     * Returns the full SupplierResource so the picker can select it at once.
     */
    public function quickAddSupplier(QuickAddSupplier $request, SupplierService $suppliers)
    {
        $supplier = $suppliers->quickCreate($request->validated());

        return (new SupplierResource($supplier))
            ->response()
            ->setStatusCode(201);
    }

    // ── Change 18: order material requirements at the Material Prep stage ──

    /** Orders currently sitting at the Material Prep (mass) stage. */
    public function ordersAtStage()
    {
        return response()->json([
            'data' => $this->requirements->ordersAtMaterialPrep(),
        ]);
    }

    /** Requirement state for an order (saved requirement, else a suggestion). */
    public function orderRequirements(int $orderId)
    {
        $order = Order::findOrFail($orderId);

        return response()->json([
            'data' => $this->requirements->stateForOrder($order),
        ]);
    }

    /** Save the confirmed requirement → create MR + auto-process (Auto-PR). */
    public function saveOrderRequirements(SaveMaterialRequirement $request, int $orderId)
    {
        $order = Order::findOrFail($orderId);

        $result = $this->requirements->saveForOrder(
            $order,
            $request->validated()['items'],
            $request->user(),
        );

        return response()->json(['data' => $result], 201);
    }

    /**
     * POST /api/v2/portal/material-prep/order/{orderId}/prep-done
     *
     * Manual fallback for the (rare) order whose Material Prep stage has no
     * purchase requests to receive — nothing to buy, or sourcing handled
     * off-system. Completes the active Material Prep stage directly so the
     * order isn't stuck waiting for a PR that will never arrive.
     *
     * Orders that DO have PRs auto-complete on the last receive (see
     * OrderStagesService::completeMaterialPrepIfReady); this is only the
     * no-PR escape hatch, gated by the same shared-queue ownership rule the
     * production "Done" uses. The route already enforces portal.material-prep.
     */
    public function markPrepDone(
        Request $request,
        int $orderId,
        OrderStagesService $stages,
        PortalAssignmentService $assignments,
    ) {
        $stage = $stages->activeMaterialPrepStage($orderId);
        if (! $stage) {
            return response()->json(
                ['message' => 'No active Material Prep stage for this order.'],
                422,
            );
        }

        if (! $assignments->userMayActOnStage($request->user(), 'material_prep', $stage)) {
            return response()->json(
                ['message' => 'This material-prep task is not in your queue.'],
                403,
            );
        }

        try {
            $next = $stages->markComplete(
                $stage->id,
                'Material prep marked done (no purchase requests to receive).',
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Could not complete material prep.',
                'errors'  => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message'   => 'Material prep completed.',
            'completed' => [
                'order_stage_id' => $stage->id,
                'order_id'       => $stage->order_id,
                'stage'          => $stage->stage,
            ],
            'next' => $next ? [
                'order_stage_id' => $next->id,
                'order_id'       => $next->order_id,
                'stage'          => $next->stage,
                'status'         => $next->status,
            ] : null,
        ]);
    }
}