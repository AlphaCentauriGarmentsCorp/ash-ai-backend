<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderStage;
use App\Services\OrderStagesService;
use App\Services\PortalAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
    /**
     * Portal roles whose production stage completes with a plain "Done"
     * (no extra capture). QA/Packer is intentionally excluded — it keeps its
     * richer submit (reject / repair capture) through QaPackerSubmitService —
     * as are Material Prep and Logistics, which complete via their own
     * event-driven paths (PR received / proof-of-delivery upload).
     */
    private const DONE_ROLES = [
        'graphic_artist', 'screen_maker', 'cutter', 'printer', 'sewer',
    ];

    public function __construct(
        protected PortalAssignmentService $assignments,
        protected OrderStagesService $stages,
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
     * POST /api/v2/portal/{role}/stages/{orderStage}/done
     *
     * The worker marks their current production stage finished from inside
     * their portal. OrderStagesService::markComplete() completes the stage and
     * auto-advances the workflow — promoting the next tier, or BOTH branches at
     * the sample fork, or holding the join until both branches are done. Who +
     * when is captured in the stage audit log (markComplete → writeAudit uses
     * the authenticated user).
     *
     * Authorisation is two-layered because {role} is a wildcard:
     *   1. the user must HOLD this portal (portal.{role}); otherwise they could
     *      target another role's unassigned shared work by editing the URL, and
     *   2. the stage must be in their shared queue (userMayActOnStage).
     * Stage status / sequence guards live in markComplete (422 on violation).
     */
    public function done(Request $request, string $role, int $orderStage)
    {
        $normalized = $this->resolveRole($role);
        if ($normalized === null || ! in_array($normalized, self::DONE_ROLES, true)) {
            return response()->json(['message' => 'This portal has no Done action.'], 404);
        }

        $user = $request->user();

        if (! $user->can('portal.' . str_replace('_', '-', $normalized))) {
            return response()->json(['message' => 'You do not have access to this portal.'], 403);
        }

        $stage = OrderStage::find($orderStage);
        if ($stage === null) {
            return response()->json(['message' => 'Stage not found.'], 404);
        }

        if (! $this->assignments->userMayActOnStage($user, $normalized, $stage)) {
            return response()->json(['message' => 'This task is not in your queue.'], 403);
        }

        try {
            $next = $this->stages->markComplete($stage->id, $request->input('notes'));
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Stage could not be completed.',
                'errors'  => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message'   => 'Stage completed.',
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
            // Refreshed queue so the portal can update in one round-trip.
            'tasks' => $this->assignments->activeTasks($user, $normalized),
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