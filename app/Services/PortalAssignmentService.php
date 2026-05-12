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
 *   1. Find OrderStage rows where assigned_to = $userId AND status is
 *      active (in_progress / for_approval / delayed). This catches
 *      explicit assignments by GM / admin / etc.
 *   2. Filter by the portal role's stage slugs (so a Cutter only ever
 *      sees stages where cutting work happens, even if they were
 *      mistakenly assigned elsewhere).
 *   3. Return single/multiple/none based on the result count.
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

        $stages = OrderStage::query()
            ->where('assigned_to', $user->id)
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
