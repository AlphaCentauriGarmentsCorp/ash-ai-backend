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
use App\Services\SupplierService;
use Illuminate\Http\Request;

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
}