<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScreenMaker\StoreScreenAssignment;
use App\Services\ScreenAssignmentService;
use App\Services\ScreenMakerPortalService;
use Illuminate\Http\Request;

/**
 * Phase 5-F — Screen Maker Portal endpoints.
 *
 * Endpoints:
 *   GET    /api/v2/portal/screen-maker/context/{orderStageId}
 *   POST   /api/v2/portal/screen-maker/screens        (SM Rework CP2)
 *   DELETE /api/v2/portal/screen-maker/screens/{id}    (SM Rework CP2)
 *
 * Notes:
 *   - Notes + mark-as-done still go through the existing
 *     OrderStagesController endpoints (no portal-specific writes there).
 *   - SM Rework CP2 adds the one portal-specific write this role needs:
 *     picking a physical screen for a placement/colour slot. This is
 *     what actually populates screen_assignments — the table the
 *     Printer Portal and CSR Review Hub already read from.
 *
 * Gated by portal.screen-maker permission (note the hyphen) at the route
 * group. ScreenAssignmentService additionally checks access.screen-making
 * per-write, same permission the legacy POST /screen-making endpoint used.
 */
class ScreenMakerPortalController extends Controller
{
    public function __construct(
        protected ScreenMakerPortalService $context,
        protected ScreenAssignmentService $screenAssignments,
    ) {
    }

    public function showContext(int $orderStageId)
    {
        $payload = $this->context->buildContext($orderStageId);
        return response()->json(['data' => $payload]);
    }

    /**
     * POST /portal/screen-maker/screens
     * Save (or swap) the screen for one placement/colour slot.
     */
    public function storeScreenAssignment(StoreScreenAssignment $request)
    {
        $result = $this->screenAssignments->assign($request->validated(), $request->user());
        $assignment = $result['assignment'];

        return response()->json([
            'data' => [
                'id'            => $assignment->id,
                'order_id'      => $assignment->order_id,
                'placement_id'  => $assignment->placement_id,
                'color_index'   => $assignment->color_index,
                'screen' => $assignment->screen ? [
                    'id'         => $assignment->screen->id,
                    'name'       => $assignment->screen->name,
                    'size'       => $assignment->screen->size,
                    'mesh_count' => $assignment->screen->mesh_count,
                    'address'    => $assignment->screen->address,
                    'status'     => $assignment->screen->status,
                ] : null,
            ],
            'conflict' => $result['conflict'],
        ], 201);
    }

    /**
     * DELETE /portal/screen-maker/screens/{id}
     * Clear a slot. Frees the screen if nothing else currently holds it.
     */
    public function destroyScreenAssignment(int $id, Request $request)
    {
        $this->screenAssignments->unassign($id, $request->user());
        return response()->json(['message' => 'Screen assignment removed'], 200);
    }
}
