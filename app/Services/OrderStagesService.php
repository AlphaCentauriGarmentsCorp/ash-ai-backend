<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStage;
use App\Support\WorkflowStages;
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
        return DB::transaction(function () use ($stageId, $notes) {
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

            $stage->update([
                'status'       => OrderStage::STATUS_COMPLETED,
                'completed_at' => now(),
                'notes'        => $notes ?? $stage->notes,
            ]);

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
                }
            }

            // Refresh cached current_stage_id + workflow_status on the order
            $order = Order::find($stage->order_id);
            if ($order) {
                $this->refreshOrderCache($order);
            }

            return $next?->fresh();
        });
    }

    /**
     * Moves a stage into "for_approval" (e.g. sample submitted, waiting CSR).
     */
    public function markForApproval(int $stageId, ?string $notes = null): OrderStage
    {
        return DB::transaction(function () use ($stageId, $notes) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            if ($stage->status !== OrderStage::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'stage' => 'Only an in_progress stage can be sent for approval.',
                ]);
            }

            $stage->update([
                'status' => OrderStage::STATUS_FOR_APPROVAL,
                'notes'  => $notes ?? $stage->notes,
            ]);

            return $stage->fresh();
        });
    }

    /**
     * Flags the current stage as delayed. Does NOT block work continuing.
     * (Phase 2 will use this to fire notifications.)
     */
    public function markDelayed(int $stageId, string $reason): OrderStage
    {
        return DB::transaction(function () use ($stageId, $reason) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            $stage->update([
                'status'     => OrderStage::STATUS_DELAYED,
                'delayed_at' => now(),
                'notes'      => $reason,
            ]);

            // Mirror on the order itself for fast lookups.
            // Only set delayed_at if the order doesn't already have one – we
            // want to preserve the FIRST time a delay was noticed.
            $order = Order::find($stage->order_id);
            if ($order && ! $order->delayed_at) {
                $order->update(['delayed_at' => now()]);
            }

            return $stage->fresh();
        });
    }

    /**
     * Puts a stage on hold (manual pause).
     */
    public function markOnHold(int $stageId, ?string $reason = null): OrderStage
    {
        return DB::transaction(function () use ($stageId, $reason) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            $stage->update([
                'status' => OrderStage::STATUS_ON_HOLD,
                'notes'  => $reason ?? $stage->notes,
            ]);

            return $stage->fresh();
        });
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

            $stage->update([
                'status'     => OrderStage::STATUS_IN_PROGRESS,
                'started_at' => $stage->started_at ?: now(),
            ]);

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

        $stage->update([
            'assigned_to'   => $userId,
            'assigned_role' => $role ?: $stage->assigned_role,
        ]);

        return $stage->fresh();
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
}
