<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Models\StageRejectLog;
use App\Models\StageSubcontractAssignment;
use App\Models\StageWasteLog;
use App\Support\WorkflowStages;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Phase 4 — Production reports.
 *
 * Aggregates data from the audit log + waste/reject logs into the
 * shapes needed for weekly/monthly reports and per-order timelines.
 *
 * Phase 6 will build dashboards on top of these endpoints. Phase 4
 * just makes sure the data layer is fast and correct.
 *
 * Both endpoints gated by access.reports.
 */
class ReportsController extends Controller
{
    /**
     * GET /api/v2/reports/production-summary
     *
     * Query params:
     *   from   — YYYY-MM-DD (optional, default = 30 days ago)
     *   to     — YYYY-MM-DD (optional, default = today)
     *   phase  — sample | mass | all  (default = all)
     *
     * Returns aggregated counts + cycle-time stats for the window.
     * The "phase" filter narrows by sample-vs-mass classification
     * (using WorkflowStages::phaseFor() under the hood).
     */
    public function productionSummary(Request $request)
    {
        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : now()->subDays(30)->startOfDay();
        $to = $request->query('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfDay();
        $phaseFilter = $request->query('phase', 'all');

        // Build the set of stage slugs for the phase filter.
        $phaseStageSlugs = $this->stageSlugsForPhase($phaseFilter);

        // ── Order counts ──
        // Orders that started in the window: had any audit row with action=started in the window.
        // (Falls back to created_at for orders that pre-date Phase 4 audit logging.)
        $startedOrderIds = StageAuditLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('action', StageAuditLog::ACTION_STARTED)
            ->pluck('order_id')
            ->unique();

        $startedFromCreatedAt = Order::whereBetween('created_at', [$from, $to])
            ->pluck('id');

        $ordersStartedIds = $startedOrderIds->merge($startedFromCreatedAt)->unique();

        // Orders that completed in the window: workflow_status reached terminal state
        // OR final stage has completed_at in the window.
        $ordersCompletedIds = OrderStage::query()
            ->whereBetween('completed_at', [$from, $to])
            ->where('sequence', WorkflowStages::maxTier())
            ->where('status', OrderStage::STATUS_COMPLETED)
            ->pluck('order_id')
            ->unique();

        $ordersInProgress = Order::query()
            ->whereNotNull('current_stage_id')
            ->whereNotIn('workflow_status', ['order_completed', 'cancelled'])
            ->where('created_at', '<=', $to)
            ->count();

        // ── Production output (waste + reject + completed pieces) ──
        // For sample/mass split, the waste/reject tables don't have a phase
        // column; we infer from the linked stage slug.
        $wasteQuery = StageWasteLog::query()
            ->whereBetween('created_at', [$from, $to]);
        $rejectQuery = StageRejectLog::query()
            ->whereBetween('created_at', [$from, $to]);

        if ($phaseStageSlugs !== null) {
            $stageIdsInPhase = OrderStage::whereIn('stage', $phaseStageSlugs)->pluck('id');
            $wasteQuery->whereIn('order_stage_id', $stageIdsInPhase);
            $rejectQuery->whereIn('order_stage_id', $stageIdsInPhase);
        }

        $totalWastePcs  = (int) $wasteQuery->sum('quantity_pcs');
        $totalRejectPcs = (int) $rejectQuery->sum('quantity_pcs');

        // ── Cycle time per stage (avg of duration_seconds & business_duration_seconds
        //    on stage_audit_logs rows where action=completed) ──
        $cycleQuery = StageAuditLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('action', StageAuditLog::ACTION_COMPLETED)
            ->whereNotNull('duration_seconds');

        if ($phaseStageSlugs !== null) {
            $stageIdsInPhase = $stageIdsInPhase ?? OrderStage::whereIn('stage', $phaseStageSlugs)->pluck('id');
            $cycleQuery->whereIn('order_stage_id', $stageIdsInPhase);
        }

        $cycleRows = $cycleQuery->get(['order_stage_id', 'duration_seconds', 'business_duration_seconds']);

        $avgCycle         = $cycleRows->avg('duration_seconds');
        $avgCycleBusiness = $cycleRows->avg('business_duration_seconds');

        // Per-stage breakdown: average duration per stage slug.
        $byStage = $cycleRows
            ->groupBy(fn ($row) => optional(OrderStage::find($row->order_stage_id))->stage ?? 'unknown')
            ->map(fn ($rows, $slug) => [
                'stage'                => $slug,
                'count'                => $rows->count(),
                'avg_seconds'          => (int) round($rows->avg('duration_seconds') ?? 0),
                'avg_business_seconds' => (int) round($rows->avg('business_duration_seconds') ?? 0),
            ])
            ->values();

        // ── Delays ──
        $delaysQuery = StageAuditLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('action', StageAuditLog::ACTION_DELAYED);

        if ($phaseStageSlugs !== null) {
            $delaysQuery->whereIn('order_stage_id', $stageIdsInPhase);
        }

        $delayRows = $delaysQuery->get(['order_stage_id']);
        $delaysByStage = $delayRows
            ->groupBy(fn ($row) => optional(OrderStage::find($row->order_stage_id))->stage ?? 'unknown')
            ->map(fn ($rows, $slug) => [
                'stage' => $slug,
                'count' => $rows->count(),
            ])
            ->values();

        // ── Phase-specific produced counts ──
        // For sample/mass we want pieces completed in the window. We use
        // the "completed" audit row's order_stage_id, look up the order's
        // total quantity, and sum.
        // (Order-level pcs aren't in the schema yet — we use sum of items_json
        // counts. For Phase 4 reports we approximate using a fixed lookup
        // method below; if you need exact pcs counts we can add an
        // order.total_quantity column in a follow-up.)
        $samplesCompletedPcs = $this->piecesCompletedInPhase($from, $to, 'sample');
        $massCompletedPcs    = $this->piecesCompletedInPhase($from, $to, 'mass');

        return response()->json([
            'period' => [
                'from' => $from->toDateString(),
                'to'   => $to->toDateString(),
            ],
            'phase' => $phaseFilter,

            'orders' => [
                'started'     => $ordersStartedIds->count(),
                'completed'   => $ordersCompletedIds->count(),
                'in_progress' => $ordersInProgress,
            ],

            'production' => [
                'samples_completed_pcs' => $samplesCompletedPcs,
                'mass_completed_pcs'    => $massCompletedPcs,
                'total_waste_pcs'       => $totalWastePcs,
                'total_reject_pcs'      => $totalRejectPcs,
            ],

            'timing' => [
                'avg_cycle_seconds'           => $avgCycle === null ? null : (int) round($avgCycle),
                'avg_cycle_business_seconds'  => $avgCycleBusiness === null ? null : (int) round($avgCycleBusiness),
                'avg_per_stage'               => $byStage,
            ],

            'delays' => [
                'count'    => $delayRows->count(),
                'by_stage' => $delaysByStage,
            ],
        ]);
    }

    /**
     * GET /api/v2/orders/{id}/production-timeline
     *
     * Returns the per-stage timeline for one order — used by the
     * Activity Log tab on the order detail page (Phase 4 Layer 4-5).
     */
    public function orderTimeline(int $id)
    {
        $order = Order::find($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $stages = OrderStage::where('order_id', $id)
            ->orderBy('sequence')
            ->get();

        // Bulk-load durations and waste/reject totals so we don't N+1.
        $stageIds = $stages->pluck('id');

        $completedAudits = StageAuditLog::whereIn('order_stage_id', $stageIds)
            ->where('action', StageAuditLog::ACTION_COMPLETED)
            ->get()
            ->keyBy('order_stage_id');

        $delayedAudits = StageAuditLog::whereIn('order_stage_id', $stageIds)
            ->where('action', StageAuditLog::ACTION_DELAYED)
            ->get()
            ->groupBy('order_stage_id');

        $wasteByStage = StageWasteLog::whereIn('order_stage_id', $stageIds)
            ->selectRaw('order_stage_id, SUM(quantity_pcs) AS total_pcs')
            ->groupBy('order_stage_id')
            ->pluck('total_pcs', 'order_stage_id');

        $rejectByStage = StageRejectLog::whereIn('order_stage_id', $stageIds)
            ->selectRaw('order_stage_id, SUM(quantity_pcs) AS total_pcs')
            ->groupBy('order_stage_id')
            ->pluck('total_pcs', 'order_stage_id');

        $subcontractedStageIds = StageSubcontractAssignment::whereIn('order_stage_id', $stageIds)
            ->pluck('order_stage_id')
            ->unique()
            ->values()
            ->all();

        $timeline = $stages->map(function ($stage) use (
            $completedAudits, $delayedAudits, $wasteByStage, $rejectByStage, $subcontractedStageIds,
        ) {
            $audit = $completedAudits[$stage->id] ?? null;

            return [
                'stage'    => $stage->stage,
                'sequence' => $stage->sequence,
                'phase'    => WorkflowStages::phaseFor($stage->stage),
                'status'   => $stage->status,

                'started_at'   => $stage->started_at?->toDateTimeString(),
                'completed_at' => $stage->completed_at?->toDateTimeString(),
                'delayed_at'   => $stage->delayed_at?->toDateTimeString(),

                'duration_seconds'          => $audit?->duration_seconds,
                'business_duration_seconds' => $audit?->business_duration_seconds,

                'delayed'       => isset($delayedAudits[$stage->id]),
                'delay_count'   => isset($delayedAudits[$stage->id]) ? $delayedAudits[$stage->id]->count() : 0,

                'waste_pcs'     => (int) ($wasteByStage[$stage->id] ?? 0),
                'reject_pcs'    => (int) ($rejectByStage[$stage->id] ?? 0),
                'subcontracted' => in_array($stage->id, $subcontractedStageIds, true),

                'assigned_role' => $stage->assigned_role,
            ];
        });

        return response()->json([
            'order' => [
                'id'              => $order->id,
                'po_code'         => $order->po_code,
                'client_brand'    => $order->client_brand,
                'client_name'     => $order->client_name,
                'workflow_status' => $order->workflow_status,
            ],
            'timeline' => $timeline,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @param string $phase one of: sample | mass | all
     * @return array<int,string>|null array of stage slugs, or null for "all"
     */
    protected function stageSlugsForPhase(string $phase): ?array
    {
        if ($phase === 'all') {
            return null;
        }

        $slugs = [];
        foreach (WorkflowStages::all() as $stage) {
            if (WorkflowStages::phaseFor($stage['key']) === $phase) {
                $slugs[] = $stage['key'];
            }
        }
        return $slugs;
    }

    /**
     * Pieces completed in a phase during the window.
     *
     * "Completed" = the final stage of the phase reached `completed`
     * status with `completed_at` in the window. We treat each such
     * order as contributing its `items_json` count of pieces.
     *
     * Approximation note: items_json is JSON, so we sum the inner
     * `quantity` fields by decoding in PHP rather than via SQL. For
     * orders with a small number of items this is fine. If later we
     * need a faster path, add an `order.total_quantity` column.
     */
    protected function piecesCompletedInPhase(Carbon $from, Carbon $to, string $phase): int
    {
        $phaseSlugs = $this->stageSlugsForPhase($phase);
        if (! $phaseSlugs) {
            return 0;
        }

        // Find the LAST stage of the phase (highest sequence) for each phase.
        $lastSlug = end($phaseSlugs);

        $orderIds = OrderStage::query()
            ->where('stage', $lastSlug)
            ->where('status', OrderStage::STATUS_COMPLETED)
            ->whereBetween('completed_at', [$from, $to])
            ->pluck('order_id')
            ->unique();

        if ($orderIds->isEmpty()) {
            return 0;
        }

        $total = 0;
        Order::whereIn('id', $orderIds)
            ->select(['id', 'items_json'])
            ->chunk(50, function ($orders) use (&$total) {
                foreach ($orders as $order) {
                    $items = json_decode($order->items_json ?? '[]', true) ?: [];
                    foreach ($items as $item) {
                        $total += (int) ($item['quantity'] ?? 0);
                    }
                }
            });

        return $total;
    }
}
