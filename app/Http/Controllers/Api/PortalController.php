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
        // Sanity-check role slug against the registry. Rejects garbage.
        $allowedRoles = [
            'csr', 'finance',
            'graphic_artist', 'screen_maker', 'sample_maker',
            'cutter', 'printer', 'sewer',
            'quality_assurance', 'qa',
            'packer', 'logistics', 'general_manager',
            'material_prep', 'purchasing', 'warehouse_manager',
            'graphic-artist', 'screen-maker', 'sample-maker',
            'material-prep', 'warehouse-manager',
        ];

        if (! in_array($role, $allowedRoles, true)) {
            return response()->json(['message' => 'Unknown portal role.'], 404);
        }

        // Normalize hyphens to underscores so the service's match() works.
        $normalized = str_replace('-', '_', $role);

        $result = $this->assignments->myActive($request->user(), $normalized);

        return response()->json($result);
    }
}
