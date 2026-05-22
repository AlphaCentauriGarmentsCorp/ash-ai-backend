<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\User;
use App\Support\WorkflowStages;
use Illuminate\Support\Collection;

/**
 * Phase 5-A — Resolves "what is the user's currently-active assignment?"
 * for the role portal landing page.
 *
 * Returns one of:
 *   - { status: 'single',   assignment: {...} }  → portal renders directly
 *   - { status: 'multiple', assignments: [...] } → portal shows a picker
 *   - { status: 'none' }                          → portal shows empty state
 *
 * Resolution strategy (in order):
 *   1. Find OrderStage rows that are active (in_progress / for_approval /
 *      delayed) AND (assigned_to = $userId OR assigned_to IS NULL). This
 *      is the "mix-mode" shared queue (Workstream B): a user sees both the
 *      work explicitly assigned to them by a manager AND the unassigned
 *      work waiting at their station. Stages assigned to a DIFFERENT user
 *      are hidden, so a manager's explicit assignment is respected.
 *   2. Filter by the portal role's stage slugs (so a Cutter only ever
 *      sees stages where cutting work happens — this also scopes the
 *      unassigned shared pool to the correct role).
 *   3. Return single/multiple/none based on the result count. A shared
 *      queue naturally returns 'multiple' more often; the portal's picker
 *      handles that case.
 *
 * For roles where stage-based assignment doesn't apply (e.g. material_prep,
 * which works off Phase 3 PRs not stages), this service returns 'none'
 * by design — the portal page itself overrides with PR-based logic.
 */
class PortalAssignmentService
{
    /**
     * @return array{status:string, assignment?:array, assignments?:array}
     */
    public function myActive(User $user, string $portalRole): array
    {
        $stageSlugs = WorkflowStages::stagesForPortalRole($portalRole);

        if (empty($stageSlugs)) {
            return ['status' => 'none'];
        }

        $activeStatuses = [
            OrderStage::STATUS_IN_PROGRESS,
            OrderStage::STATUS_FOR_APPROVAL,
            OrderStage::STATUS_DELAYED,
        ];

        // Mix-mode shared queue (Workstream B):
        //   - assigned_to = me           → my explicit assignment (manager set it)
        //   - assigned_to IS NULL        → unassigned shared work at my station
        //   - assigned_to = someone else → HIDDEN (respects manager's override)
        //
        // The whereIn('stage', $stageSlugs) below already scopes results to
        // this role's stations, so an unassigned stage only surfaces in the
        // portal of the role that owns it. This is what makes an active-but-
        // unassigned stage (e.g. graphic_artwork with assigned_to=null) show
        // up for the Graphic Artist instead of silently stalling.
        $stages = OrderStage::query()
            ->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                    ->orWhereNull('assigned_to');
            })
            ->whereIn('status', $activeStatuses)
            ->whereIn('stage', $stageSlugs)
            ->orderBy('updated_at', 'desc')
            ->get();

        if ($stages->isEmpty()) {
            return ['status' => 'none'];
        }

        if ($stages->count() === 1) {
            return [
                'status'     => 'single',
                'assignment' => $this->summarizeStage($stages->first()),
            ];
        }

        return [
            'status'      => 'multiple',
            'assignments' => $stages->map(fn ($s) => $this->summarizeStage($s))->all(),
        ];
    }

    /**
     * Compact representation of an OrderStage for the picker.
     * Includes enough context for the user to choose between assignments
     * without paying for a full Order resource hydration.
     */
    protected function summarizeStage(OrderStage $stage): array
    {
        $order = Order::select([
            'id', 'po_code', 'client_name', 'client_brand',
            'workflow_status',
        ])->find($stage->order_id);

        return [
            'order_stage_id' => $stage->id,
            'order_id'       => $stage->order_id,
            'stage'          => $stage->stage,
            'sequence'       => $stage->sequence,
            'status'         => $stage->status,
            'started_at'     => $stage->started_at?->toDateTimeString(),
            'order'          => $order ? [
                'id'              => $order->id,
                'po_code'         => $order->po_code,
                'client_name'     => $order->client_name,
                'client_brand'    => $order->client_brand,
                'workflow_status' => $order->workflow_status,
            ] : null,
        ];
    }
}