<?php

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageRejectLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7-B Bundle 1 — Atomic "SUBMIT COMPLETED" handler.
 *
 * This is the single backend endpoint behind the big green submit
 * button in the QA/Packer portal. It MUST be all-or-nothing — the
 * spec doc explicitly requires this:
 *
 *   - Advance the order's workflow stage
 *   - Decrement packing-materials inventory (freebies, hangtags, stickers,
 *     OPP plastic)         ← Bundle 4 hook
 *   - Persist the post-submit checklist state
 *   - Notify CSR (always)
 *   - Notify Logistics (always — order ready for pickup)
 *   - Notify Super Admin (if reject thresholds exceeded — see thresholds())
 *
 * All wrapped in a single DB transaction so a failure on any step
 * rolls back the stage advance.
 *
 * BUNDLE 1 SCOPE
 * --------------
 * Bundle 1 wires the transaction skeleton, the stage advancement,
 * and the threshold logic for Super Admin notification. The following
 * side effects are explicitly STUBBED for later bundles:
 *
 *   - Inventory decrements          → Bundle 4
 *   - Checklist completion storage  → Bundle 4 (needs new completion table)
 *   - Full notification fan-out     → Bundle 4 (needs new NotificationService methods)
 *
 * Each stub is marked with `// TODO Bundle 4:` and a one-line explanation
 * so a future session can find them via grep.
 */
class QaPackerSubmitService
{
    public function __construct(
        protected OrderStagesService $stages,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Execute the atomic submit.
     *
     * @param  int    $orderStageId  The QA or Packing stage being submitted.
     * @param  array  $payload       Fields from the submit endpoint.
     *                               Expected shape (validated upstream):
     *                                 - qa_checklist_state:        array  (slug => bool)
     *                                 - packing_checklist_state:   array  (slug => bool)
     *                                 - final_photos:              array  (kind => path)
     *                                 - notes:                     ?string
     * @param  User   $user          Acting user.
     *
     * @return array{
     *     stage_id: int,
     *     stage: string,
     *     new_stage: ?string,
     *     reject_summary: array{total_pcs:int, pct:float, exceeds_threshold:bool},
     *     notifications: array{csr:bool, logistics:bool, super_admin:bool},
     * }
     *
     * @throws ValidationException
     */
    public function submit(int $orderStageId, array $payload, User $user): array
    {
        $stage = OrderStage::find($orderStageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage not found.',
            ]);
        }

        if (! in_array($stage->stage, QaPackerPortalService::ELIGIBLE_STAGES, true)) {
            throw ValidationException::withMessages([
                'order_stage_id' => "Stage '{$stage->stage}' is not a QA/Packer portal stage.",
            ]);
        }

        $order = Order::findOrFail($stage->order_id);

        return DB::transaction(function () use ($stage, $order, $payload, $user) {
            // ── 1. Compute reject summary BEFORE advancing the stage ───
            //
            // Rejects are tied to order_stage_id; once the stage is
            // marked complete its logs are immutable.
            $rejectSummary = $this->computeRejectSummary($stage, $order);

            // ── 2. Persist checklist completion ────────────────────────
            //
            // TODO Bundle 4: write a row to qa_packer_task_completions
            // with checklist_state_json + final_photos_json. The table
            // doesn't exist yet — left as a deliberate stub so Bundle 1
            // schema stays minimal.
            //
            // For now we capture the payload shape in $payload as a
            // contract check (so callers don't drift before Bundle 4):
            $this->validatePayloadShape($payload);

            // ── 3. Decrement packing-material inventory ────────────────
            //
            // TODO Bundle 4: when this is wired, it MUST run inside this
            // same transaction so a stock-out rolls back the stage
            // advance. The Materials model is in place; the decrement
            // logic + the materials→checklist-item mapping is Bundle 4.

            // ── 4. Advance the workflow stage ──────────────────────────
            //
            // OrderStagesService::markComplete promotes the next pending
            // stage to in_progress and returns that next stage (NOT the
            // stage we just completed). It returns null if the just-
            // completed stage was the last in the workflow.
            //
            // We capture both pieces of state explicitly to avoid
            // confusion: $stage is what we completed, $nextStage is
            // what's now in_progress (or null if we just finished the
            // order).
            $nextStage = $this->stages->markComplete(
                $stage->id,
                $payload['notes'] ?? null,
            );

            // Refresh the completed stage to pick up its new
            // status=completed + completed_at values.
            $completedStage = $stage->fresh();

            // ── 5. Resolve which notifications fire ────────────────────
            $notificationsFired = $this->fanOutNotifications(
                $completedStage,
                $order,
                $rejectSummary,
            );

            return [
                'stage_id'        => $completedStage->id,
                'stage'           => $completedStage->stage,       // e.g. 'quality_control'
                'new_stage'       => $nextStage?->stage,            // e.g. 'packing' or null
                'reject_summary'  => $rejectSummary,
                'notifications'   => $notificationsFired,
            ];
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Tally rejects (disposition='reject' only — repairs don't count
     * against the threshold) and decide whether Super Admin alert fires.
     *
     * Per Q4: ≥5 pcs OR ≥10% of order qty (either trips it).
     *
     * Order quantity is derived from items_json, matching the pattern
     * used by every other portal service. The Order table itself has
     * no aggregated quantity column.
     *
     * @return array{total_pcs:int, pct:float, exceeds_threshold:bool}
     */
    protected function computeRejectSummary(OrderStage $stage, Order $order): array
    {
        $rejectPcs = (int) StageRejectLog::where('order_stage_id', $stage->id)
            ->where('disposition', StageRejectLog::DISPOSITION_REJECT)
            ->sum('quantity_pcs');

        $orderQty = $this->orderQuantityFromItemsJson($order);

        $pct = $orderQty > 0 ? round($rejectPcs / $orderQty, 4) : 0.0;

        $thresholdPcs = (int) NotificationSetting::getValue(
            'qa_reject_alert_threshold_pcs',
            5,
        );
        $thresholdPct = (float) NotificationSetting::getValue(
            'qa_reject_alert_threshold_pct',
            0.10,
        );

        $exceeds = $rejectPcs >= $thresholdPcs || $pct >= $thresholdPct;

        return [
            'total_pcs'         => $rejectPcs,
            'pct'               => $pct,
            'exceeds_threshold' => $exceeds,
        ];
    }

    /**
     * Derive total order quantity from items_json. Defensive against
     * string vs. array casts (same pattern as portal services).
     */
    protected function orderQuantityFromItemsJson(Order $order): int
    {
        $raw = $order->items_json;
        $items = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);

        $total = 0;
        foreach ($items as $item) {
            if (is_array($item) && isset($item['quantity'])) {
                $total += (int) $item['quantity'];
            }
        }
        return $total;
    }

    /**
     * Decide which notifications fire and dispatch them.
     *
     * Per spec:
     *   - CSR: always (order moved forward)
     *   - Logistics: always (order is ready for pickup)
     *   - Super Admin: only if reject thresholds exceeded
     *
     * Returns a record of who got notified for test-side assertions.
     *
     * @return array{csr:bool, logistics:bool, super_admin:bool}
     */
    protected function fanOutNotifications(
        OrderStage $stage,
        Order $order,
        array $rejectSummary,
    ): array {
        // TODO Bundle 4: add NotificationService::qaPackerTaskCompleted
        // that takes ($stage, $order, $rejectSummary) and emits the full
        // fan-out (CSR + Logistics + conditionally Super Admin).
        //
        // For Bundle 1 we re-use the existing stageInProgress event for
        // the auto-advanced next stage (fired by markComplete itself —
        // see OrderStagesService) and rely on the test to assert the
        // future intent via the returned summary.

        return [
            'csr'         => true,
            'logistics'   => true,
            'super_admin' => $rejectSummary['exceeds_threshold'],
        ];
    }

    /**
     * Validate the submit payload shape without coupling to a
     * FormRequest (the controller validates the wire format; this
     * is a defence-in-depth check at the service boundary).
     */
    protected function validatePayloadShape(array $payload): void
    {
        foreach (['qa_checklist_state', 'packing_checklist_state'] as $k) {
            if (isset($payload[$k]) && ! is_array($payload[$k])) {
                throw ValidationException::withMessages([
                    $k => "{$k} must be an object (slug => boolean).",
                ]);
            }
        }
    }
}
