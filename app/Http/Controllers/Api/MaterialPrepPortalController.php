<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaterialPrep\AssignSupplier;
use App\Services\MaterialPrepPortalService;
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
                    'id'   => $pr->supplier->id,
                    'name' => $pr->supplier->name,
                ] : null,
            ],
        ]);
    }
}
