<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Support\WorkCalendar;
use App\Support\WorkflowStages;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OrderStagesService – the sequential workflow state machine.
 *
 * Replaces the legacy "checkbox selection" model with a strict 14-stage
 * pipeline. Each Order has every stage pre-created (see initializeForOrder).
 * Only one stage at a time can be in_progress.
 *
 * State transitions:
 *   pending      → in_progress   (start())
 *   in_progress  → completed     (markComplete())  – auto-promotes next
 *   in_progress  → for_approval  (markForApproval())
 *   for_approval → completed     (markComplete())
 *   any          → delayed       (markDelayed())
 *   any          → on_hold       (markOnHold())
 *   any          → in_progress   (resume())
 *
 * Sequence is enforced: you cannot start stage N until stage N-1 is completed.
 */
class OrderStagesService
{
    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * Bulk-create every stage for a freshly stored Order. The first stage
     * is set to in_progress, the rest pending.
     *
     * Idempotent – safe to call twice; existing rows are left alone.
     */
    public function initializeForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            // 1. Prune any legacy stage rows that are NOT in the canonical
            //    14-stage workflow. Pre-Phase-1 orders may have had stages
            //    like `graphic_editing`, `sample_cutting`, etc. that no
            //    longer exist as workflow stages – they're work-page IDs
            //    now, not workflow stages.
            $canonicalKeys = WorkflowStages::keys();
            OrderStage::where('order_id', $order->id)
                ->whereNotIn('stage', $canonicalKeys)
                ->delete();

            // 2. Get whatever's left – these are the canonical stages this
            //    order already has rows for.
            $existing = OrderStage::where('order_id', $order->id)
                ->pluck('stage')
                ->all();

            // 3. Determine if ANY canonical stage is currently in_progress.
            //    If yes, we don't want to start a fresh in_progress on the
            //    first stage – we'd respect what's already running.
            $hasActiveStage = OrderStage::where('order_id', $order->id)
                ->where('status', OrderStage::STATUS_IN_PROGRESS)
                ->exists();

            // 4. Create any missing canonical stages.
            foreach (WorkflowStages::all() as $idx => $stage) {
                if (in_array($stage['key'], $existing, true)) {
                    continue;
                }

                // Default everything new to pending. The first canonical
                // stage gets set to in_progress only when this is a
                // brand-new order (no existing rows + no active stage).
                $shouldStartFirst = $idx === 0
                    && empty($existing)
                    && ! $hasActiveStage;

                OrderStage::create([
                    'order_id'      => $order->id,
                    'stage'         => $stage['key'],
                    'sequence'      => $idx + 1,
                    'status'        => $shouldStartFirst
                        ? OrderStage::STATUS_IN_PROGRESS
                        : OrderStage::STATUS_PENDING,
                    'started_at'    => $shouldStartFirst ? now() : null,
                    'assigned_role' => $stage['role'] ?? null,
                ]);
            }

            // 5. Make sure every existing row has the right sequence number
            //    (in case a legacy row was kept but its sequence was 0).
            foreach (WorkflowStages::all() as $idx => $stage) {
                OrderStage::where('order_id', $order->id)
                    ->where('stage', $stage['key'])
                    ->where('sequence', '!=', $idx + 1)
                    ->update(['sequence' => $idx + 1]);
            }

            // 6. If no stage is in_progress yet (e.g. an order that was
            //    fully pruned to just pending stages), promote the first
            //    pending one.
            $anyInProgress = OrderStage::where('order_id', $order->id)
                ->where('status', OrderStage::STATUS_IN_PROGRESS)
                ->exists();

            if (! $anyInProgress) {
                $firstPending = OrderStage::where('order_id', $order->id)
                    ->where('status', OrderStage::STATUS_PENDING)
                    ->orderBy('sequence')
                    ->first();
                if ($firstPending) {
                    $firstPending->update([
                        'status'     => OrderStage::STATUS_IN_PROGRESS,
                        'started_at' => now(),
                    ]);
                }
            }

            // Phase 4 — write a 'started' audit row for any stage that's
            // now in_progress but doesn't have one yet. Covers both the
            // initial-create-with-status='in_progress' path AND the
            // fallback promotion above. Idempotent: re-runs of
            // initializeForOrder won't write duplicates.
            $inProgressStages = OrderStage::where('order_id', $order->id)
                ->where('status', OrderStage::STATUS_IN_PROGRESS)
                ->get();

            foreach ($inProgressStages as $ip) {
                $hasStartedAudit = StageAuditLog::where('order_stage_id', $ip->id)
                    ->where('action', StageAuditLog::ACTION_STARTED)
                    ->exists();

                if (! $hasStartedAudit) {
                    $this->writeAudit(
                        $ip,
                        StageAuditLog::ACTION_STARTED,
                        OrderStage::STATUS_PENDING,
                        OrderStage::STATUS_IN_PROGRESS,
                        null,
                    );
                }
            }

            // 7. Refresh the order's cached current_stage_id + workflow_status
            $this->refreshOrderCache($order);
        });
    }

    /**
     * Returns the currently active stage for an Order (the one in_progress
     * or for_approval). Falls back to the first non-completed stage.
     */
    public function getCurrentStage(int $orderId): ?OrderStage
    {
        return OrderStage::where('order_id', $orderId)
            ->whereIn('status', [
                OrderStage::STATUS_IN_PROGRESS,
                OrderStage::STATUS_FOR_APPROVAL,
            ])
            ->orderBy('sequence')
            ->first()
            ?: OrderStage::where('order_id', $orderId)
                ->where('status', '!=', OrderStage::STATUS_COMPLETED)
                ->orderBy('sequence')
                ->first();
    }

    /**
     * Marks the current in_progress stage as completed and auto-starts the
     * next one in sequence. Returns the (now in_progress) next stage, or
     * null when the workflow is complete.
     *
     * @throws ValidationException when the stage cannot be advanced.
     */
    public function markComplete(int $stageId, ?string $notes = null): ?OrderStage
    {
        $result = DB::transaction(function () use ($stageId, $notes) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            if ($stage->status === OrderStage::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'stage' => 'This stage is already completed.',
                ]);
            }

            // Must be in progress (or for_approval) to be completable.
            if (! in_array($stage->status, [
                OrderStage::STATUS_IN_PROGRESS,
                OrderStage::STATUS_FOR_APPROVAL,
                OrderStage::STATUS_DELAYED,
            ], true)) {
                throw ValidationException::withMessages([
                    'stage' => 'Stage must be active before it can be completed. Current status: ' . $stage->status,
                ]);
            }

            // Defence-in-depth: every previous stage must already be completed.
            $earlierUnfinished = OrderStage::where('order_id', $stage->order_id)
                ->where('sequence', '<', $stage->sequence)
                ->where('status', '!=', OrderStage::STATUS_COMPLETED)
                ->exists();
            if ($earlierUnfinished) {
                throw ValidationException::withMessages([
                    'stage' => 'Cannot complete this stage – earlier stages are not yet finished.',
                ]);
            }

            $previousStatus = $stage->status;

            $stage->update([
                'status'       => OrderStage::STATUS_COMPLETED,
                'completed_at' => now(),
                'notes'        => $notes ?? $stage->notes,
            ]);

            $this->writeAudit(
                $stage->fresh(),
                StageAuditLog::ACTION_COMPLETED,
                $previousStatus,
                OrderStage::STATUS_COMPLETED,
                $notes,
            );

            // Promote the next pending stage to in_progress.
            $next = OrderStage::where('order_id', $stage->order_id)
                ->where('sequence', '>', $stage->sequence)
                ->orderBy('sequence')
                ->first();

            if ($next) {
                if ($next->status === OrderStage::STATUS_PENDING) {
                    $next->update([
                        'status'     => OrderStage::STATUS_IN_PROGRESS,
                        'started_at' => now(),
                    ]);

                    $this->writeAudit(
                        $next->fresh(),
                        StageAuditLog::ACTION_STARTED,
                        OrderStage::STATUS_PENDING,
                        OrderStage::STATUS_IN_PROGRESS,
                        null,
                    );
                }
            }

            // Refresh cached current_stage_id + workflow_status on the order
            $order = Order::find($stage->order_id);
            if ($order) {
                $this->refreshOrderCache($order);
            }

            return [
                'order' => $order,
                'next'  => $next?->fresh(),
                'wasLastStage' => $next === null,
            ];
        });

        // Notifications fire AFTER the transaction so we don't block the
        // commit and so we don't roll them back on a hypothetical retry.
        if ($result['next']) {
            $this->notifications->stageInProgress($result['next']);
        }

        if ($result['wasLastStage'] && $result['order']) {
            $this->notifications->orderCompleted($result['order']);
        }

        return $result['next'];
    }

    /**
     * Moves a stage into "for_approval" (e.g. sample submitted, waiting CSR).
     */
    public function markForApproval(int $stageId, ?string $notes = null): OrderStage
    {
        $stage = DB::transaction(function () use ($stageId, $notes) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            if ($stage->status !== OrderStage::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'stage' => 'Only an in_progress stage can be sent for approval.',
                ]);
            }

            $previousStatus = $stage->status;

            $stage->update([
                'status' => OrderStage::STATUS_FOR_APPROVAL,
                'notes'  => $notes ?? $stage->notes,
            ]);

            $this->writeAudit(
                $stage->fresh(),
                StageAuditLog::ACTION_FOR_APPROVAL,
                $previousStatus,
                OrderStage::STATUS_FOR_APPROVAL,
                $notes,
            );

            return $stage->fresh();
        });

        $this->notifications->stageForApproval($stage, $notes);

        return $stage;
    }

    /**
     * Flags the current stage as delayed. Does NOT block work continuing.
     * (Phase 2 will use this to fire notifications.)
     */
    public function markDelayed(int $stageId, string $reason): OrderStage
    {
        $stage = DB::transaction(function () use ($stageId, $reason) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            $previousStatus = $stage->status;

            $stage->update([
                'status'     => OrderStage::STATUS_DELAYED,
                'delayed_at' => now(),
                'notes'      => $reason,
            ]);

            $this->writeAudit(
                $stage->fresh(),
                StageAuditLog::ACTION_DELAYED,
                $previousStatus,
                OrderStage::STATUS_DELAYED,
                $reason,
            );

            // Mirror on the order itself for fast lookups.
            // Only set delayed_at if the order doesn't already have one – we
            // want to preserve the FIRST time a delay was noticed.
            $order = Order::find($stage->order_id);
            if ($order && ! $order->delayed_at) {
                $order->update(['delayed_at' => now()]);
            }

            return $stage->fresh();
        });

        $this->notifications->stageDelayed($stage, $reason);

        return $stage;
    }

    /**
     * Puts a stage on hold (manual pause).
     */
    public function markOnHold(int $stageId, ?string $reason = null): OrderStage
    {
        $stage = DB::transaction(function () use ($stageId, $reason) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            $previousStatus = $stage->status;

            $stage->update([
                'status' => OrderStage::STATUS_ON_HOLD,
                'notes'  => $reason ?? $stage->notes,
            ]);

            $this->writeAudit(
                $stage->fresh(),
                StageAuditLog::ACTION_ON_HOLD,
                $previousStatus,
                OrderStage::STATUS_ON_HOLD,
                $reason,
            );

            return $stage->fresh();
        });

        $this->notifications->stageOnHold($stage, $reason);

        return $stage;
    }

    /**
     * Returns a stage that was on_hold or delayed back to in_progress.
     */
    public function resume(int $stageId): OrderStage
    {
        return DB::transaction(function () use ($stageId) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            if (! in_array($stage->status, [
                OrderStage::STATUS_ON_HOLD,
                OrderStage::STATUS_DELAYED,
            ], true)) {
                throw ValidationException::withMessages([
                    'stage' => 'Only on_hold or delayed stages can be resumed.',
                ]);
            }

            $previousStatus = $stage->status;

            $stage->update([
                'status'     => OrderStage::STATUS_IN_PROGRESS,
                'started_at' => $stage->started_at ?: now(),
            ]);

            $this->writeAudit(
                $stage->fresh(),
                StageAuditLog::ACTION_RESUMED,
                $previousStatus,
                OrderStage::STATUS_IN_PROGRESS,
                null,
            );

            return $stage->fresh();
        });
    }

    /**
     * Assigns a stage to a specific user.
     */
    public function assign(int $stageId, ?int $userId, ?string $role = null): OrderStage
    {
        /** @var OrderStage $stage */
        $stage = OrderStage::findOrFail($stageId);

        $previousAssignee = $stage->assigned_to;

        $stage->update([
            'assigned_to'   => $userId,
            'assigned_role' => $role ?: $stage->assigned_role,
        ]);

        $stage = $stage->fresh();

        // Only notify if the assignee actually changed and there's a target user.
        if ($userId && $userId !== $previousAssignee) {
            $this->notifications->stageAssigned($stage, $userId);
        }

        return $stage;
    }

    /**
     * Recomputes Order.workflow_status + Order.current_stage_id from
     * the order_stages table.
     */
    public function refreshOrderCache(Order $order): void
    {
        $current = $this->getCurrentStage($order->id);

        $order->update([
            'workflow_status'   => $current?->stage ?? 'order_completed',
            'current_stage_id'  => $current?->id,
            // Clear delayed_at on the order if no stage is currently delayed.
            'delayed_at'        => $this->anyStageDelayed($order->id) ? ($order->delayed_at ?: now()) : null,
        ]);
    }

    private function anyStageDelayed(int $orderId): bool
    {
        return OrderStage::where('order_id', $orderId)
            ->where('status', OrderStage::STATUS_DELAYED)
            ->exists();
    }

    // ──────────────────────────────────────────────────────────────────
    // Phase 4 — audit log writer
    //
    // Every state transition appends a row to stage_audit_logs inside
    // the same DB transaction as the state change, so the two can't
    // drift. On terminal transitions ('completed' / 'cancelled') we
    // also look up the matching 'started' row and compute the
    // wall-clock + business-hours duration from it.
    // ──────────────────────────────────────────────────────────────────

    /**
     * Write an audit row for a stage transition.
     *
     * MUST be called inside the same DB transaction as the state change.
     *
     * @param OrderStage  $stage      the stage being transitioned (post-update)
     * @param string      $action     StageAuditLog::ACTION_*
     * @param string|null $fromStatus status before the transition
     * @param string|null $toStatus   status after the transition
     * @param string|null $notes      optional reason (e.g., delay reason)
     */
    protected function writeAudit(
        OrderStage $stage,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $notes = null,
    ): StageAuditLog {
        $userId = Auth::id();

        $durationSeconds = null;
        $businessSeconds = null;

        // For terminal transitions, find the most recent 'started' row
        // for this stage and compute durations from it.
        $isTerminal = in_array($action, [
            StageAuditLog::ACTION_COMPLETED,
            StageAuditLog::ACTION_CANCELLED,
        ], true);

        if ($isTerminal) {
            $startedRow = StageAuditLog::where('order_stage_id', $stage->id)
                ->where('action', StageAuditLog::ACTION_STARTED)
                ->orderByDesc('id')
                ->first();

            if ($startedRow && $startedRow->created_at) {
                $now = now();
                // abs() guards against Carbon 3's signed diffInSeconds
                // behavior (see WorkCalendar for context).
                $durationSeconds = abs($now->diffInSeconds($startedRow->created_at));
                $businessSeconds = WorkCalendar::businessSecondsBetween(
                    $startedRow->created_at,
                    $now,
                );
            }
        }

        return StageAuditLog::create([
            'order_id'                  => $stage->order_id,
            'order_stage_id'            => $stage->id,
            'user_id'                   => $userId,
            'action'                    => $action,
            'from_status'               => $fromStatus,
            'to_status'                 => $toStatus,
            'duration_seconds'          => $durationSeconds,
            'business_duration_seconds' => $businessSeconds,
            'notes'                     => $notes,
            'created_at'                => now(),
        ]);
    }
}
