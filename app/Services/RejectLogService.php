<?php

namespace App\Services;

use App\Models\OrderStage;
use App\Models\StageRejectLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7-B Bundle 1 — Write-side service for reject/repair log creation
 * from the QA/Packer portal.
 *
 * Per Q2 decision: a repair entry is just a row with disposition='repair'
 * — no rework task spawning. CSR notification fires for both rejects
 * and repairs; the recipient fan-out is delegated to NotificationService
 * (which already has stageRejectLogged() — Bundle 4 will extend it to
 * disposition-aware fan-out plus cutter-cc on fabric_issue).
 */
class RejectLogService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Create a reject/repair log entry.
     *
     * @param  array  $data  Validated fields from StoreReject FormRequest.
     *                       Must include: order_id, order_stage_id, disposition,
     *                       reject_reason_id, quantity_pcs.
     *                       May include: photo_path (already-stored path), notes.
     * @param  User   $user  Acting user (logged_by).
     *
     * @throws ValidationException if the stage isn't a QA/Packer stage or isn't active.
     */
    public function create(array $data, User $user): StageRejectLog
    {
        $stage = OrderStage::find($data['order_stage_id']);
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

        if (! in_array($stage->status, ['in_progress', 'for_approval', 'delayed'], true)) {
            throw ValidationException::withMessages([
                'order_stage_id' => "Cannot log against a stage with status '{$stage->status}'.",
            ]);
        }

        // Cross-check order_id matches stage's order_id (defence in depth).
        if ((int) $data['order_id'] !== (int) $stage->order_id) {
            throw ValidationException::withMessages([
                'order_id' => 'order_id does not match the stage.',
            ]);
        }

        return DB::transaction(function () use ($data, $user) {
            $log = StageRejectLog::create([
                'order_id'          => $data['order_id'],
                'order_stage_id'    => $data['order_stage_id'],
                'logged_by_user_id' => $user->id,
                'quantity_pcs'      => $data['quantity_pcs'],
                'disposition'       => $data['disposition'],
                'reject_reason_id'  => $data['reject_reason_id'],
                'photo_path'        => $data['photo_path'] ?? null,
                'notes'             => $data['notes'] ?? null,
            ]);

            // Fan out notifications. NotificationService::stageRejectLogged
            // already exists for reject events. Bundle 4 will extend it to
            // be disposition-aware (and add cutter-cc on fabric_issue).
            // For now we fire it for both reject and repair; CSR will see
            // both, which matches the spec's "if repair only: notify CSR".
            $this->notifications->stageRejectLogged($log->fresh(['reason']));

            return $log;
        });
    }

    /**
     * Delete a reject/repair log entry. Only the original logger or a
     * manager (RBAC-controlled at the route layer) can hit this method.
     *
     * @throws ValidationException if the log doesn't exist or doesn't belong to user.
     */
    public function delete(int $logId, User $user): void
    {
        $log = StageRejectLog::find($logId);
        if (! $log) {
            throw ValidationException::withMessages([
                'id' => 'Reject log not found.',
            ]);
        }

        // Original logger only — managers should use a separate admin endpoint.
        if ((int) $log->logged_by_user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'id' => 'You can only delete your own reject/repair entries.',
            ]);
        }

        $log->delete();
    }
}
