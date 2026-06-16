<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageReview;
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

    // ── Batch 2: active-task list (Change 2) + badge counts (Change 3) ─────

    /**
     * "Active workload" statuses: everything queued or in flight, but not
     * finished, on hold, or cancelled. Drives both the My-Active list and the
     * sidebar badge counts so they stay a single source of truth.
     */
    public const ACTIVE_WORKLOAD_STATUSES = [
        OrderStage::STATUS_PENDING,
        OrderStage::STATUS_IN_PROGRESS,
        OrderStage::STATUS_FOR_APPROVAL,
        OrderStage::STATUS_DELAYED,
    ];

    /** Portal roles that map to one or more workflow stages (for badges). */
    public const STAGE_PORTAL_ROLES = [
        'graphic_artist', 'screen_maker', 'cutter', 'printer', 'sewer',
        'material_prep', 'qa_packer', 'logistics',
    ];

    /** Roles with org-wide oversight: they see every portal's badge total. */
    public const OVERSIGHT_ROLES = ['superadmin', 'admin', 'csr'];

    /**
     * Change 2 — the role's "My Active Tasks" queue for $user.
     *
     * Same shared-queue scoping as myActive() (assigned to me OR unassigned at
     * my station), but returns the FULL active workload as rich rows, sorted
     * FIFO (oldest first) with Rush-flagged orders pinned to the top.
     *
     * @return array{count:int, tasks:array<int,array>}
     */
    public function activeTasks(User $user, string $portalRole): array
    {
        $stageSlugs = WorkflowStages::stagesForPortalRole($portalRole);
        if (empty($stageSlugs)) {
            return ['count' => 0, 'tasks' => []];
        }

        $stages = OrderStage::query()
            ->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)->orWhereNull('assigned_to');
            })
            ->whereIn('status', self::ACTIVE_WORKLOAD_STATUSES)
            ->whereIn('stage', $stageSlugs)
            ->get();

        return $this->buildTaskList($this->frontierStages($stages));
    }

    /**
     * Change 3 — count of ALL active tasks at a role's stages (not user-scoped).
     * Used for the oversight badge: the station's total active workload.
     */
    public function activeCountForRole(string $portalRole): int
    {
        $stageSlugs = WorkflowStages::stagesForPortalRole($portalRole);
        if (empty($stageSlugs)) {
            return 0;
        }

        $stages = OrderStage::query()
            ->whereIn('status', self::ACTIVE_WORKLOAD_STATUSES)
            ->whereIn('stage', $stageSlugs)
            ->get();

        // Same frontier filter as activeTasks() so the oversight badge and the
        // portal list stay one source of truth (Bundle 1.1).
        return $this->frontierStages($stages)->count();
    }

    /**
     * Frontier filter (Bundle 1.1) — given candidate stage rows already limited
     * to a portal role's stages and the active-workload statuses, keep only the
     * ones actionable at the station *right now*:
     *
     *   - any NON-pending active row (in_progress / delayed / for_approval) —
     *     work already underway at the station; and
     *   - a PENDING row only if the fork-join engine would start it now for its
     *     order, i.e. its slug is in WorkflowStages::nextActivations() for the
     *     order's full stage map.
     *
     * Why this exists: OrderStagesService::initializeForOrder() pre-creates ALL
     * canonical stages for an order, the future ones as 'pending'. Without this
     * filter every order surfaces a pending row at every station it will EVER
     * pass through — so a Cutter saw both the sample_cutting AND the mass_cutting
     * of every order, plus orders nowhere near cutting yet. Reusing
     * nextActivations() keeps the one parallel tier correct: while tier 6 is
     * live both screen_making and material_prep_sample qualify; the join
     * (sample_cutting, tier 7) waits until both complete.
     *
     * Applied identically by activeTasks() (the list) and activeCountForRole()
     * (the oversight badge), so the two can never disagree. NOTE: the legacy
     * single-task resolver myActive() is intentionally NOT filtered — the
     * portals no longer use it (Bundle 1) and PortalAssignmentTest pins its
     * current behaviour; it can be removed in a later cleanup.
     *
     * @param  Collection<int,OrderStage>  $candidates
     * @return Collection<int,OrderStage>
     */
    protected function frontierStages(Collection $candidates): Collection
    {
        $pending = $candidates->filter(
            static fn (OrderStage $s) => $s->status === OrderStage::STATUS_PENDING
        );

        // No pending rows to second-guess → every candidate is current work.
        if ($pending->isEmpty()) {
            return $candidates->values();
        }

        // Pending rows need their order's FULL canonical stage map, because
        // nextActivations() reasons over every stage of the order.
        $orderIds = $pending->pluck('order_id')->unique()->all();

        $eligibleByOrder = OrderStage::query()
            ->whereIn('order_id', $orderIds)
            ->get(['order_id', 'stage', 'status'])
            ->groupBy('order_id')
            ->map(static fn ($rows) => WorkflowStages::nextActivations(
                $rows->pluck('status', 'stage')->all()
            ))
            ->all();

        return $candidates->filter(static function (OrderStage $s) use ($eligibleByOrder) {
            if ($s->status !== OrderStage::STATUS_PENDING) {
                return true; // in_progress / delayed / for_approval — current work
            }
            return in_array(
                $s->stage,
                $eligibleByOrder[$s->order_id] ?? [],
                true
            );
        })->values();
    }

    /**
     * Change 3 — per-portal badge counts, honouring visibility:
     *   - oversight roles (superadmin/admin/csr) → every portal's station total
     *   - everyone else → only portals they hold portal.{slug} for, counted
     *     against their own shared queue (so the badge matches their list).
     *
     * @return array<string,int>  portalRole => active count
     */
    public function badgeCounts(User $user): array
    {
        $isOversight = $user->hasAnyRole(self::OVERSIGHT_ROLES);
        $counts = [];

        foreach (self::STAGE_PORTAL_ROLES as $role) {
            if ($isOversight) {
                $counts[$role] = $this->activeCountForRole($role);
                continue;
            }

            // Regular user: only their own portal(s), scoped to their queue.
            $perm = 'portal.' . str_replace('_', '-', $role);
            if ($user->can($perm)) {
                $counts[$role] = $this->activeTasks($user, $role)['count'];
            }
        }

        return $counts;
    }

    /**
     * Turn a collection of OrderStage rows into sorted, hydrated task rows.
     * Rush orders pinned to top; otherwise FIFO by queue age (oldest first).
     */
    protected function buildTaskList(Collection $stages): array
    {
        if ($stages->isEmpty()) {
            return ['count' => 0, 'tasks' => []];
        }

        $orders = Order::query()
            ->whereIn('id', $stages->pluck('order_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $forRevision = $this->forRevisionStageIds($stages->pluck('id')->all());

        $rows = $stages->map(fn (OrderStage $s) => $this->taskRow(
            $s,
            $orders->get($s->order_id),
            in_array($s->id, $forRevision, true),
        ))->values();

        // Rush pinned to top; within each group, FIFO (oldest queue age first).
        $sorted = $rows->sort(function ($a, $b) {
            if ($a['rush'] !== $b['rush']) {
                return $a['rush'] ? -1 : 1;
            }
            return strcmp((string) $a['queue_age_at'], (string) $b['queue_age_at']);
        })->values()->all();

        return ['count' => count($sorted), 'tasks' => $sorted];
    }

    /**
     * Which of these order_stage_ids are currently "For Revision" — their
     * latest advisory review decision is a reject not yet followed by a
     * resubmit. (stage_reviews is append-only; highest id is the latest.)
     *
     * @param  array<int,int>  $stageIds
     * @return array<int,int>
     */
    protected function forRevisionStageIds(array $stageIds): array
    {
        if (empty($stageIds)) {
            return [];
        }

        return StageReview::query()
            ->whereIn('order_stage_id', $stageIds)
            ->orderBy('id', 'desc')
            ->get(['order_stage_id', 'decision'])
            ->unique('order_stage_id')
            ->where('decision', StageReview::DECISION_REJECT)
            ->pluck('order_stage_id')
            ->all();
    }

    /** Rich row for the My-Active list. */
    protected function taskRow(OrderStage $stage, ?Order $order, bool $forRevision): array
    {
        $rush = $order
            ? ((bool) ($order->rush_order ?? false) || ($order->priority ?? null) === Order::PRIORITY_RUSH)
            : false;

        // Queue age = when the order entered this stage (start, else created).
        $queueAgeAt = $stage->started_at ?? $stage->created_at;

        return [
            'order_stage_id' => $stage->id,
            'order_id'       => $stage->order_id,
            'stage'          => $stage->stage,
            'sequence'       => $stage->sequence,
            'status'         => $stage->status,
            'for_revision'   => $forRevision,
            // "For Revision" overrides the raw status for the badge display.
            'display_status' => $forRevision ? 'for_revision' : $stage->status,
            'rush'           => $rush,
            'queue_age_at'   => $queueAgeAt?->toDateTimeString(),
            'project_no'     => $order?->po_code,
            'client_name'    => $order?->client_name,
            'client_brand'   => $order?->client_brand,
            'quantity'       => $order?->total_quantity,
            'color'          => $order?->shirt_color,
            'print_area'     => $this->printArea($order),
            // due_date intentionally omitted — no such field yet (Change 2).
        ];
    }

    /**
     * "Print Area" for a row: distinct placement names from the order's print
     * parts (e.g. "Front, Back"), falling back to the print_area column.
     */
    protected function printArea(?Order $order): ?string
    {
        if (! $order) {
            return null;
        }

        $parts = $order->print_parts_json;
        if (is_array($parts) && ! empty($parts)) {
            $names = collect($parts)
                ->map(fn ($p) => is_array($p) ? ($p['part'] ?? $p['placement'] ?? null) : null)
                ->filter()
                ->unique()
                ->values();
            if ($names->isNotEmpty()) {
                return $names->implode(', ');
            }
        }

        return $order->print_area;
    }
}