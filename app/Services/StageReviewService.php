<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageReview;
use App\Models\User;
use App\Support\WorkflowStages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CSR Review Hub — approve / reject / resubmit a stage's output.
 *
 * ─── Design (owner-confirmed, this session) ────────────────────────────────
 * This is an ADVISORY layer that sits BESIDE the linear stage engine, never
 * inside it. It deliberately does NOT:
 *   - change order_stages.status,
 *   - move the workflow pointer (Order.current_stage_id / workflow_status),
 *   - block downstream stages.
 *
 * The order keeps advancing while the owning role fixes a rejection in
 * parallel. We chose this so the proven OrderStagesService engine and its
 * "earlier stages must be completed" guard stay completely untouched (the
 * handoff's explicit "do NOT bolt onto the spine" caution).
 *
 * Consequently, "is this stage currently rejected?" is NOT a status — it is
 * DERIVED from the stage's review ledger:
 *   - The LATEST StageReview row for a stage is its current review state.
 *   - latest.decision === 'reject'   → OPEN REJECTION (needs resubmit).
 *   - latest.decision === 'resubmit' → awaiting re-review.
 *   - latest.decision === 'approve'  → approved.
 *   - no rows                        → not yet reviewed.
 *
 * Action rules:
 *   - approve / reject : reviewers only (csr / super_admin / admin — gated at
 *     the route layer). A reviewer may act on ANY stage at any time, including
 *     an already-completed one (an earlier stage found bad later). reject
 *     REQUIRES a comment; image optional.
 *   - resubmit : the OWNING role. Only valid when there's an OPEN REJECTION.
 *     Closes the rejection and notifies the reviewers for re-review.
 */
class StageReviewService
{
    public function __construct(
        private NotificationService $notifications,
        private OrderStagesService $stages,
    ) {}

    /**
     * Reviewer approves a stage's output.
     *
     * This is now the SINGLE approval action (the Workflow Timeline's
     * "Approve & Complete" was removed to avoid two competing approve buttons).
     * Behaviour:
     *   - Always records an 'approve' review row (the audit/sign-off).
     *   - If the stage is awaiting a decision (for_approval / in_progress /
     *     delayed), it ALSO advances the workflow via
     *     OrderStagesService::markComplete — i.e. marks the stage completed and
     *     promotes the next stage. This is the one place that advances on approval.
     *   - If the stage is already completed (a late sign-off on past work), it
     *     just records the review and does not touch the engine.
     *
     * markComplete enforces the "earlier stages finished" guard, so approving
     * out of order is rejected at the engine level, not here.
     */
    public function approve(int $stageId, User $reviewer, ?string $comment = null): StageReview
    {
        return DB::transaction(function () use ($stageId, $reviewer, $comment) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            // Change 17 — a payment-verification gate is a Finance approval, not
            // a production review. Passing it requires action.verify-payment
            // regardless of entry point, so the Review Hub cannot be used to
            // bypass the gate. (The button is also hidden client-side, but a
            // forged request must still be refused here.)
            if (WorkflowStages::isPaymentGate($stage->stage)
                && ! $reviewer->can('action.verify-payment')) {
                abort(403, 'Only Finance, Superadmin, or Admin can approve a payment-verification gate.');
            }

            $review = StageReview::create([
                'order_id'       => $stage->order_id,
                'order_stage_id' => $stage->id,
                'actor_user_id'  => $reviewer->id,
                'decision'       => StageReview::DECISION_APPROVE,
                'comment'        => $comment,
                'image_path'     => null,
            ]);

            // Advance the workflow when the stage is in an advanceable state.
            // Completed stages are left alone (late sign-off only records the row).
            $advanceable = in_array($stage->status, [
                OrderStage::STATUS_IN_PROGRESS,
                OrderStage::STATUS_FOR_APPROVAL,
                OrderStage::STATUS_DELAYED,
            ], true);

            if ($advanceable) {
                // Reuses the proven engine: completes this stage + promotes next,
                // writes its own audit, and enforces the earlier-stages guard.
                $this->stages->markComplete($stageId, $comment);
            }

            return $review;
        });
        // No notification on approve in v1 — approval is silent.
    }

    /**
     * Reviewer rejects a stage's output.
     *
     * @param string      $comment   REQUIRED human reason (enforced at request layer too).
     * @param string|null $imagePath already-stored path on the 'public' disk (controller handles upload).
     */
    public function reject(int $stageId, User $reviewer, string $comment, ?string $imagePath = null): StageReview
    {
        if (trim($comment) === '') {
            throw ValidationException::withMessages([
                'comment' => 'A comment is required when rejecting a stage.',
            ]);
        }

        $review = DB::transaction(function () use ($stageId, $reviewer, $comment, $imagePath) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            return StageReview::create([
                'order_id'       => $stage->order_id,
                'order_stage_id' => $stage->id,
                'actor_user_id'  => $reviewer->id,
                'decision'       => StageReview::DECISION_REJECT,
                'comment'        => $comment,
                'image_path'     => $imagePath,
            ]);
        });

        // Notify the owning role AFTER commit (mirrors OrderStagesService).
        $stage = OrderStage::find($stageId);
        if ($stage) {
            $this->notifications->stageRejected($stage, $comment);
        }

        return $review;
    }

    /**
     * Owning role resubmits a previously-rejected stage's output.
     *
     * Only valid when the stage currently has an OPEN REJECTION. Records a
     * 'resubmit' row (closing the rejection) and notifies the reviewers.
     *
     * @param string|null $comment optional note describing what was fixed.
     */
    public function resubmit(int $stageId, User $actor, ?string $comment = null): StageReview
    {
        $review = DB::transaction(function () use ($stageId, $actor, $comment) {
            /** @var OrderStage $stage */
            $stage = OrderStage::lockForUpdate()->findOrFail($stageId);

            if (! $this->hasOpenRejection($stage->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'This stage has no open rejection to resubmit against.',
                ]);
            }

            return StageReview::create([
                'order_id'       => $stage->order_id,
                'order_stage_id' => $stage->id,
                'actor_user_id'  => $actor->id,
                'decision'       => StageReview::DECISION_RESUBMIT,
                'comment'        => $comment,
                'image_path'     => null,
            ]);
        });

        $stage = OrderStage::find($stageId);
        if ($stage) {
            $this->notifications->stageResubmitted($stage, $comment);
        }

        return $review;
    }

    // ──────────────────────────────────────────────────────────────────
    // Derived state — the single source of truth for "review status"
    // ──────────────────────────────────────────────────────────────────

    /**
     * The latest review row for a stage (its current review state), or null
     * if the stage has never been reviewed.
     */
    public function latestReview(int $stageId): ?StageReview
    {
        // Notes are ledger-only: exclude them so the derived review state and
        // the portals' open-rejection banners are driven purely by the
        // approve / reject / resubmit decisions.
        return StageReview::where('order_stage_id', $stageId)
            ->whereIn('decision', StageReview::allDecisions())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Staff note — a plain, append-only ledger entry on a stage's record.
     *
     * The Review Hub is a notes-only surface (owner decision, this session):
     * the Approve/Reject buttons were removed from the hub UI and staff leave
     * freeform notes instead. A note NEVER touches the decision state machine
     * — latestReview() filters to approve/reject/resubmit, so an open
     * rejection stays open (portal banners unaffected) and review_state
     * ignores notes entirely.
     *
     * Any authenticated staff who can read the order may post (route-gated on
     * access.orders, matching the hub's read access). Comment required.
     */
    public function note(int $stageId, User $author, string $comment): StageReview
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => ['A note cannot be empty.'],
            ]);
        }

        $stage = OrderStage::findOrFail($stageId);

        $review = StageReview::create([
            'order_id'       => $stage->order_id,
            'order_stage_id' => $stage->id,
            'actor_user_id'  => $author->id,
            'decision'       => StageReview::DECISION_NOTE,
            'comment'        => $comment,
        ]);

        return $review->load('actor:id,name');
    }

    /**
     * True when the stage's latest review is a 'reject' — i.e. it is waiting
     * on the owning role to resubmit. This is what the production portals read
     * to show the rejection banner + re-enable the resubmit action.
     */
    public function hasOpenRejection(int $stageId): bool
    {
        return $this->latestReview($stageId)?->decision === StageReview::DECISION_REJECT;
    }

    /**
     * A compact review-state descriptor for a stage, for the hub + portals:
     *   review_state:   'none' | 'approved' | 'rejected' | 'resubmitted'
     *   open_rejection: bool   (latest is a reject → needs resubmit)
     *   stage_status:   the stage's workflow status (pending/in_progress/…)
     *   can_approve:    show the Approve button? (only when actionable)
     *   can_reject:     show the Reject button?  (only when actionable)
     *   latest:         summarized latest row (or null)
     *
     * Button visibility rules (owner-confirmed):
     *   - Approve shows ONLY when the stage is genuinely awaiting a decision —
     *     i.e. its workflow status is for_approval / in_progress / delayed AND
     *     it isn't already approved. Hidden for pending/locked, completed, and
     *     already-approved stages (prevents approve spam). Since Approve now
     *     advances the workflow, a completed stage is never approvable again.
     *   - Reject shows when there is real output to reject — the stage has
     *     started (not pending) — and it isn't already sitting in an open
     *     rejection (no point rejecting twice before a resubmit).
     */
    public function stateFor(int $stageId, ?User $viewer = null): array
    {
        $latest = $this->latestReview($stageId);
        $stage  = OrderStage::find($stageId);
        $stageStatus = $stage?->status;

        $state = match ($latest?->decision) {
            StageReview::DECISION_APPROVE   => 'approved',
            StageReview::DECISION_REJECT    => 'rejected',
            StageReview::DECISION_RESUBMIT  => 'resubmitted',
            default                         => 'none',
        };

        $openRejection = $latest?->decision === StageReview::DECISION_REJECT;

        // Approvable only while the stage is active and not already completed.
        $awaitingDecision = in_array($stageStatus, [
            OrderStage::STATUS_IN_PROGRESS,
            OrderStage::STATUS_FOR_APPROVAL,
            OrderStage::STATUS_DELAYED,
        ], true);

        $started = $stageStatus !== null
            && $stageStatus !== OrderStage::STATUS_PENDING;

        // Change 17 — a payment-verification gate is a Finance approval, not a
        // production review. Hide Approve from anyone without
        // action.verify-payment so the Review Hub never offers a button the
        // backend will reject. (The server still refuses a forged request.)
        $isPaymentGate = $stage && WorkflowStages::isPaymentGate($stage->stage);
        $mayPassGate    = ! $isPaymentGate
            || (bool) $viewer?->can('action.verify-payment');

        return [
            'review_state'   => $state,
            'open_rejection' => $openRejection,
            'stage_status'   => $stageStatus,
            // Hide Approve once approved/completed or before the stage is active,
            // and on a payment gate unless the viewer may pass it.
            'can_approve'    => $awaitingDecision && $state !== 'approved' && $mayPassGate,
            // Hide Reject before the stage has output or while already rejected,
            // and on a payment gate unless the viewer may pass it (Change 17 —
            // payment verification is a Finance action, read-only for others).
            'can_reject'     => $started && ! $openRejection && $mayPassGate,
            'latest'         => $latest ? $this->summarize($latest) : null,
        ];
    }

    /**
     * Full chronological review history for an order, grouped by stage id.
     * Used by the hub's history view.
     *
     * @return Collection<int, array> keyed by order_stage_id
     */
    public function historyForOrder(int $orderId): Collection
    {
        return StageReview::where('order_id', $orderId)
            ->with('actor:id,name')
            ->orderBy('id')
            ->get()
            ->groupBy('order_stage_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => $this->summarize($r))->values());
    }

    /**
     * Compact representation of a StageReview for API responses.
     */
    public function summarize(StageReview $review): array
    {
        return [
            'id'             => $review->id,
            'order_id'       => $review->order_id,
            'order_stage_id' => $review->order_stage_id,
            'decision'       => $review->decision,
            'comment'        => $review->comment,
            'image_path'     => $review->image_path,
            'image_url'      => $review->image_path
                ? asset('storage/' . $review->image_path)
                : null,
            'actor'          => $review->relationLoaded('actor') && $review->actor
                ? ['id' => $review->actor->id, 'name' => $review->actor->name]
                : ['id' => $review->actor_user_id, 'name' => null],
            'created_at'     => $review->created_at?->toDateTimeString(),
        ];
    }
}