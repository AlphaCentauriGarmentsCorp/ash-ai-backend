<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\PurchaseRequest;
use App\Models\StageAuditLog;
use App\Support\WorkCalendar;
use App\Support\WorkflowStages;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OrderStagesService – the sequential workflow state machine.
 *
 * Replaces the legacy "checkbox selection" model with a strict 16-stage
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
    protected ?OrderPaymentService $payments;

    public function __construct(
        NotificationService $notifications,
        ?OrderPaymentService $payments = null,
    ) {
        $this->notifications = $notifications;
        $this->payments = $payments;
    }

    /**
     * The payment service, lazily resolved from the container if it was not
     * injected. Constructor auto-wiring does not reliably populate an optional
     * (nullable, defaulted) dependency, so we fall back to the container — this
     * guarantees gate-payment creation fires on init/advance while still
     * allowing a test to inject a stub via the constructor.
     */
    private function resolvePayments(): OrderPaymentService
    {
        return $this->payments ??= app(OrderPaymentService::class);
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
            //    16-stage workflow. Pre-Phase-1 orders may have had stages
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

            // 3b. Progress high-water mark — the highest CANONICAL sequence
            //     this order has already reached (completed OR currently
            //     active). Used to safely backfill stages that are inserted
            //     into the MIDDLE of the workflow.
            //
            //     Why this matters: when a new stage is added to the
            //     canonical list BEFORE a point an existing order has
            //     already passed (e.g. inserting the mass-payment +
            //     purchase-materials gates while an order is already at
            //     mass_production), creating that stage as `pending` would
            //     sit an unfinished stage BEHIND the order's current
            //     position. markComplete's integrity guard ("all earlier
            //     stages must be completed") would then permanently STALL
            //     the order. To avoid that, a stage inserted at or behind
            //     the high-water mark is created as `completed` (the order
            //     has demonstrably moved past that point — the gate is
            //     moot and the work happened off-system).
            //
            //     On a fresh order (no existing rows) this is 0, so the
            //     branch never fires — new orders are unaffected.
            $progressHighWater = 0;
            $progressedStages = OrderStage::where('order_id', $order->id)
                ->whereIn('status', [
                    OrderStage::STATUS_COMPLETED,
                    OrderStage::STATUS_IN_PROGRESS,
                    OrderStage::STATUS_FOR_APPROVAL,
                    OrderStage::STATUS_DELAYED,
                    OrderStage::STATUS_ON_HOLD,
                ])
                ->pluck('stage')
                ->all();
            foreach ($progressedStages as $progressedSlug) {
                $seq = WorkflowStages::sequenceOf($progressedSlug);
                if ($seq !== null && $seq > $progressHighWater) {
                    $progressHighWater = $seq;
                }
            }

            // 4. Create any missing canonical stages.
            $backfilledStageIds = [];
            foreach (WorkflowStages::all() as $idx => $stage) {
                if (in_array($stage['key'], $existing, true)) {
                    continue;
                }

                $sequence = $stage['seq'];

                // Default everything new to pending. The first canonical
                // stage gets set to in_progress only when this is a
                // brand-new order (no existing rows + no active stage).
                $shouldStartFirst = $idx === 0
                    && empty($existing)
                    && ! $hasActiveStage;

                // Backfill guard: a stage inserted at or behind the order's
                // progress high-water mark is marked completed, not pending,
                // so it can't stall the order on markComplete's guard.
                // (Never applies when $shouldStartFirst — that's stage 1 of
                // a brand-new order, where high-water is 0.)
                $isBackfill = ! $shouldStartFirst
                    && $progressHighWater > 0
                    && $sequence <= $progressHighWater;

                $status = match (true) {
                    $shouldStartFirst => OrderStage::STATUS_IN_PROGRESS,
                    $isBackfill       => OrderStage::STATUS_COMPLETED,
                    default           => OrderStage::STATUS_PENDING,
                };

                $created = OrderStage::create([
                    'order_id'      => $order->id,
                    'stage'         => $stage['key'],
                    'sequence'      => $sequence,
                    'status'        => $status,
                    'started_at'    => $shouldStartFirst ? now() : ($isBackfill ? now() : null),
                    'completed_at'  => $isBackfill ? now() : null,
                    'assigned_role' => $stage['role'] ?? null,
                ]);

                if ($isBackfill) {
                    $backfilledStageIds[] = $created->id;
                }
            }

            // 5. Make sure every existing row has the right sequence number
            //    (in case a legacy row was kept but its sequence was 0).
            foreach (WorkflowStages::all() as $stage) {
                OrderStage::where('order_id', $order->id)
                    ->where('stage', $stage['key'])
                    ->where('sequence', '!=', $stage['seq'])
                    ->update(['sequence' => $stage['seq']]);
            }

            // 6. Promote whatever tier is now eligible. This handles the
            //    normal "start stage 1" case AND a parallel fork tier, and is
            //    idempotent: nextActivations() returns [] when a stage is
            //    already active, or when a fork branch is still waiting on its
            //    sibling. (On a fresh order, step 4's $shouldStartFirst has
            //    already started inquiry, so this is a no-op there.)
            $this->promoteEligible($order);

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

            // Backfill audit — write a 'completed' row for each stage that
            // was inserted as already-completed (status='completed' set at
            // creation above because it sits behind the order's progress
            // high-water mark). We write it directly rather than via
            // writeAudit() so we DON'T fabricate a duration from a
            // non-existent 'started' row; the note records why the stage
            // appears completed without a normal start→complete cycle.
            // Idempotent: skipped if a completed audit row already exists.
            foreach ($backfilledStageIds as $bfId) {
                $alreadyLogged = StageAuditLog::where('order_stage_id', $bfId)
                    ->where('action', StageAuditLog::ACTION_COMPLETED)
                    ->exists();

                if (! $alreadyLogged) {
                    StageAuditLog::create([
                        'order_id'                  => $order->id,
                        'order_stage_id'            => $bfId,
                        'user_id'                   => Auth::id(),
                        'action'                    => StageAuditLog::ACTION_COMPLETED,
                        'from_status'               => OrderStage::STATUS_PENDING,
                        'to_status'                 => OrderStage::STATUS_COMPLETED,
                        'duration_seconds'          => null,
                        'business_duration_seconds' => null,
                        'notes'                     => 'Auto-backfilled: stage added to the workflow after this order had already progressed past this point. Work occurred off-system before the stage existed.',
                        'created_at'                => now(),
                    ]);
                }
            }

            // 7. Refresh the order's cached current_stage_id + workflow_status
            $this->refreshOrderCache($order);

            // 8. If the (now) active stage is a payment gate, surface its
            //    pending payment on the Dashboard approvals queue immediately.
            $this->resolvePayments()->ensureGatePayment($order);
        });
    }

    /**
     * Promote every stage that is now eligible to start, using the pure
     * fork-join brain in WorkflowStages::nextActivations(). Promotes 0..n
     * stages (n>1 only at the sample-phase parallel fork). Idempotent.
     *
     * MUST be called inside a DB transaction (it writes stage + audit rows).
     *
     * @return array<int, OrderStage> the newly-started stages, lowest tier first
     */
    protected function promoteEligible(Order $order): array
    {
        $stages = OrderStage::where('order_id', $order->id)->get();

        $statusBySlug = [];
        foreach ($stages as $s) {
            $statusBySlug[$s->stage] = $s->status;
        }

        $toStart = WorkflowStages::nextActivations($statusBySlug);
        if (empty($toStart)) {
            return [];
        }

        $promoted = [];
        foreach ($toStart as $slug) {
            $row = $stages->firstWhere('stage', $slug);
            if ($row && $row->status === OrderStage::STATUS_PENDING) {
                $row->update([
                    'status'     => OrderStage::STATUS_IN_PROGRESS,
                    'started_at' => now(),
                ]);

                $this->writeAudit(
                    $row->fresh(),
                    StageAuditLog::ACTION_STARTED,
                    OrderStage::STATUS_PENDING,
                    OrderStage::STATUS_IN_PROGRESS,
                    null,
                );

                $promoted[] = $row->fresh();
            }
        }

        // Lowest tier first, so callers can treat $promoted[0] as the primary
        // next stage while still seeing every started branch.
        usort($promoted, static fn ($a, $b) => $a->sequence <=> $b->sequence);

        return $promoted;
    }

    /**
     * Returns the currently active stage for an Order (the one in_progress
     * or for_approval). Falls back to the first non-completed stage.
     *
     * During the sample-phase fork TWO stages are in_progress at once
     * (tier 6: screen_making + material_prep_sample). This returns the
     * lowest-tier one deterministically (sequence, then id) so the cached
     * Order.workflow_status is stable; the full timeline still exposes both
     * via the order_stages list.
     */
    public function getCurrentStage(int $orderId): ?OrderStage
    {
        return OrderStage::where('order_id', $orderId)
            ->whereIn('status', [
                OrderStage::STATUS_IN_PROGRESS,
                OrderStage::STATUS_FOR_APPROVAL,
            ])
            ->orderBy('sequence')
            ->orderBy('id')
            ->first()
            ?: OrderStage::where('order_id', $orderId)
                ->where('status', '!=', OrderStage::STATUS_COMPLETED)
                ->orderBy('sequence')
                ->orderBy('id')
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

            // Promote the next ELIGIBLE tier. For a normal stage this is the
            // single next stage; at the graphic_artwork → (screen_making ‖
            // material_prep_sample) fork it promotes BOTH branches at once;
            // at the join (sample_cutting) it promotes nothing until both
            // branches have completed.
            $order = Order::find($stage->order_id);
            $promoted = $order ? $this->promoteEligible($order) : [];

            // "Last stage" = no remaining non-completed stage anywhere in the
            // workflow. An empty $promoted does NOT imply completion — a fork
            // branch may simply be waiting on its sibling.
            $remaining = OrderStage::where('order_id', $stage->order_id)
                ->where('status', '!=', OrderStage::STATUS_COMPLETED)
                ->exists();

            // Refresh cached current_stage_id + workflow_status on the order
            if ($order) {
                $this->refreshOrderCache($order);
                // If advancing landed on a payment gate, surface its pending
                // payment on the Dashboard approvals queue immediately.
                $this->resolvePayments()->ensureGatePayment($order);
            }

            return [
                'order'        => $order,
                'promoted'     => $promoted,
                'next'         => $promoted[0] ?? null,
                'wasLastStage' => ! $remaining,
            ];
        });

        // Notifications fire AFTER the transaction so we don't block the
        // commit and so we don't roll them back on a hypothetical retry.
        // Notify EVERY newly-started stage — at the fork this is both the
        // Screen Maker and Material Prep branches.
        foreach ($result['promoted'] as $startedStage) {
            $this->notifications->stageInProgress($startedStage);
        }

        if ($result['wasLastStage'] && $result['order']) {
            $this->notifications->orderCompleted($result['order']);
        }

        return $result['next'];
    }

    /**
     * Sample-approval REJECT — loop the order back to graphic_artwork.
     *
     * Resets every sample-production stage the order has already run
     * (WorkflowStages::sampleStageKeys(): graphic_artwork → sample_approval)
     * back to `pending`, clearing its timestamps, then re-promotes the
     * eligible tier so graphic_artwork starts again and the sample sub-flow
     * re-runs forward. Because graphic_artwork is the lowest sample tier, the
     * tier-6 screen_making ‖ material_prep_sample fork re-fires automatically
     * when graphic_artwork completes — i.e. a reject re-makes screens and
     * re-sources sample materials too, not just the cut/print/sew build.
     *
     * The sample PAYMENT gate (payment_verification_sample, seq 4) is NOT a
     * sample-production stage (sample === false) so it is never reset — a
     * reject therefore never demands a second sample fee. Mass-phase stages
     * (seq 12+) are untouched; the order never reached them.
     *
     * History survives in the satellite tables: every reset writes a
     * StageAuditLog 'reset' row, and the prior pass's sample uploads and
     * ClientApproval rows are left intact.
     *
     * @return array<int, OrderStage> the stage(s) (re)started by the reset
     */
    public function resetSampleSubflow(Order $order, ?string $reason = null): array
    {
        $promoted = DB::transaction(function () use ($order, $reason) {
            $sampleKeys = WorkflowStages::sampleStageKeys();

            // Reset every sample-phase row that has already run (anything not
            // still pending) back to pending with cleared timestamps.
            $rows = OrderStage::where('order_id', $order->id)
                ->whereIn('stage', $sampleKeys)
                ->where('status', '!=', OrderStage::STATUS_PENDING)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $fromStatus = $row->status;

                $row->update([
                    'status'       => OrderStage::STATUS_PENDING,
                    'started_at'   => null,
                    'completed_at' => null,
                    'delayed_at'   => null,
                ]);

                $this->writeAudit(
                    $row->fresh(),
                    StageAuditLog::ACTION_RESET,
                    $fromStatus,
                    OrderStage::STATUS_PENDING,
                    $reason,
                );
            }

            // Re-promote: graphic_artwork (the lowest sample tier) restarts.
            $promoted = $this->promoteEligible($order);

            // Refresh cached current_stage_id + workflow_status on the order.
            $this->refreshOrderCache($order);

            return $promoted;
        });

        // Mirror markComplete: notify each newly-started stage's owner AFTER
        // the commit. This is what alerts the Graphic Artist on a loop-back.
        foreach ($promoted as $startedStage) {
            $this->notifications->stageInProgress($startedStage);
        }

        return $promoted;
    }

    /**
     * Bundle 2 — the order's currently-active Material Prep stage, if any.
     *
     * Material Prep owns two stages: material_prep_sample (the sample-phase
     * sourcing fork, tier 6) and material_prep_mass (mass-phase sourcing,
     * tier 13). Only one is ever active at a time. Returns the active one
     * (in_progress / delayed / for_approval), lowest tier first for safety,
     * or null when Material Prep is not the order's current work.
     */
    public function activeMaterialPrepStage(int $orderId): ?OrderStage
    {
        return OrderStage::where('order_id', $orderId)
            ->whereIn('stage', WorkflowStages::stagesForPortalRole('material_prep'))
            ->whereIn('status', [
                OrderStage::STATUS_IN_PROGRESS,
                OrderStage::STATUS_DELAYED,
                OrderStage::STATUS_FOR_APPROVAL,
            ])
            ->orderBy('sequence')
            ->orderBy('id')
            ->first();
    }

    /**
     * Bundle 2 — auto-complete the active Material Prep stage once every
     * purchase request for the order has been received.
     *
     * Called after a PR is marked received. "All received" means no PR for the
     * order is still outstanding — i.e. none left in pending / approved /
     * ordered (cancelled PRs don't block). When that holds AND a Material Prep
     * stage is active, the stage is completed and the workflow advances exactly
     * as a portal "Done" would (so the parallel sample fork joins correctly).
     * No active prep stage, or any still-outstanding PR, makes this a no-op
     * (returns null).
     *
     * The zero-PR case (nothing to buy) never reaches here — this only runs on
     * a PR-received event, so there is always at least one received PR. Orders
     * with no PRs at all advance via the portal's manual "Prep Done" fallback.
     *
     * Defensive: a ValidationException from markComplete (only reachable on a
     * pre-existing data inconsistency, since "active stage with completed
     * predecessors" is the precondition) is swallowed so it can never break the
     * PR-received response — the stage simply isn't auto-advanced.
     */
    public function completeMaterialPrepIfReady(int $orderId): ?OrderStage
    {
        $stage = $this->activeMaterialPrepStage($orderId);
        if (! $stage) {
            return null;
        }

        $hasOutstanding = PurchaseRequest::where('order_id', $orderId)
            ->whereNotIn('status', [
                PurchaseRequest::STATUS_RECEIVED,
                PurchaseRequest::STATUS_CANCELLED,
            ])
            ->exists();

        if ($hasOutstanding) {
            return null;
        }

        try {
            return $this->markComplete(
                $stage->id,
                'Auto-completed: all purchase requests received.',
            );
        } catch (ValidationException $e) {
            return null;
        }
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