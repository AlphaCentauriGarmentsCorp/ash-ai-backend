<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortalAssignmentService;
use Illuminate\Http\Request;

/**
 * Phase 5-A — Role portal landing-page endpoints.
 *
 * Each portal calls /api/v2/portal/{role}/my-active on mount to know
 * what to show. The route's permission middleware (portal.cutter etc.)
 * gates access to the wrong portal.
 *
 * The {role} segment is matched against PortalAssignmentService's
 * known portal roles. Unknown roles return 404.
 */
class PortalController extends Controller
{
    public function __construct(
        protected PortalAssignmentService $assignments,
    ) {
    }

    /**
     * GET /api/v2/portal/{role}/my-active
     *
     * Returns:
     *   { "status": "single",   "assignment":  {...} }
     *   { "status": "multiple", "assignments": [...] }
     *   { "status": "none" }
     */
    public function myActive(Request $request, string $role)
    {
        $normalized = $this->resolveRole($role);
        if ($normalized === null) {
            return response()->json(['message' => 'Unknown portal role.'], 404);
        }

        return response()->json(
            $this->assignments->myActive($request->user(), $normalized)
        );
    }

    /**
     * GET /api/v2/portal/{role}/my-active-tasks  (Change 2)
     *
     * The role's full "My Active Tasks" queue for the current user, as rich
     * rows sorted FIFO with Rush pinned to top.
     *
     * Returns: { "count": int, "tasks": [ {...}, ... ] }
     */
    public function myActiveTasks(Request $request, string $role)
    {
        $normalized = $this->resolveRole($role);
        if ($normalized === null) {
            return response()->json(['message' => 'Unknown portal role.'], 404);
        }

        return response()->json(
            $this->assignments->activeTasks($request->user(), $normalized)
        );
    }

    /**
     * GET /api/v2/portal/badge-counts  (Change 3)
     *
     * Per-portal active-task counts for the sidebar badges, already filtered to
     * what the current user may see (oversight → all stations; regular user →
     * own portal only). Returns: { "counts": { "cutter": 3, ... } }
     */
    public function badgeCounts(Request $request)
    {
        return response()->json([
            'counts' => $this->assignments->badgeCounts($request->user()),
        ]);
    }

    /**
     * Validate the {role} slug against the registry and normalise hyphens to
     * underscores so PortalAssignmentService's role matching works. Returns
     * null for unknown roles.
     */
    private function resolveRole(string $role): ?string
    {
        $allowedRoles = [
            'csr', 'finance',
            'graphic_artist', 'screen_maker', 'sample_maker',
            'cutter', 'printer', 'sewer',
            'quality_assurance', 'qa',
            'packer', 'logistics', 'general_manager',
            'material_prep', 'purchasing', 'warehouse_manager',
            'graphic-artist', 'screen-maker', 'sample-maker',
            'material-prep', 'warehouse-manager',
            'qa_packer', 'qa-packer',
        ];

        if (! in_array($role, $allowedRoles, true)) {
            return null;
        }

        return str_replace('-', '_', $role);
    }
}