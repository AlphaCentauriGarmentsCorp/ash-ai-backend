<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageRejectLog;
use App\Models\StageWasteLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 4 — Logs waste and reject quantities against an order_stage.
 *
 * Routes only call create methods after validating payload (qty + photo
 * upload). This service validates business rules:
 *   - The stage must exist
 *   - The stage must currently be 'in_progress' or 'for_approval' (not
 *     pending, completed, on_hold, or cancelled)
 *   - The actor must hold the relevant logging permission (route-level
 *     middleware also enforces this; we double-check defensively)
 *   - For deletes, the actor must hold stage_inputs.delete (managers)
 *
 * The HTTP layer (Layer 4-3) handles the photo upload itself and
 * passes the resulting storage path in as `photo_path`.
 */
class StageInputsService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    // ── Waste ────────────────────────────────────────────────────────

    /**
     * Log a waste quantity against a stage.
     *
     * @param array{order_id:int,order_stage_id:int,quantity_pcs:int,photo_path?:string|null,notes?:string|null} $data
     */
    public function logWaste(array $data, ?User $actor = null): StageWasteLog
    {
        $actor = $actor ?? Auth::user();

        $log = DB::transaction(function () use ($data, $actor) {
            $stage = $this->loadActiveStage($data['order_stage_id'], $data['order_id']);

            $this->ensureCan($actor, 'stage_inputs.log_waste');

            return StageWasteLog::create([
                'order_id'          => $stage->order_id,
                'order_stage_id'    => $stage->id,
                'logged_by_user_id' => $actor?->id,
                'quantity_pcs'      => (int) $data['quantity_pcs'],
                'photo_path'        => $data['photo_path'] ?? null,
                'notes'             => $data['notes'] ?? null,
            ]);
        });

        $this->notifications->stageWasteLogged($log);
        return $log->fresh();
    }

    /**
     * Soft-equivalent: hard delete a waste log (managers only).
     * For correcting accidents. Audit-friendly: the row is gone, but
     * the stage_audit_logs trail of state changes is unaffected.
     */
    public function deleteWasteLog(int $logId, ?User $actor = null): void
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'stage_inputs.delete');

        $log = StageWasteLog::find($logId);
        if (! $log) {
            return; // idempotent — no-op if already gone
        }

        $log->delete();
    }

    // ── Reject ───────────────────────────────────────────────────────

    /**
     * Log a reject quantity against a stage. Mostly used by QA at the
     * quality_control stage, but the schema allows logging against any
     * active stage (e.g., a downstream stage realising earlier output
     * was bad).
     *
     * @param array{order_id:int,order_stage_id:int,quantity_pcs:int,photo_path?:string|null,notes?:string|null} $data
     */
    public function logReject(array $data, ?User $actor = null): StageRejectLog
    {
        $actor = $actor ?? Auth::user();

        $log = DB::transaction(function () use ($data, $actor) {
            $stage = $this->loadActiveStage($data['order_stage_id'], $data['order_id']);

            $this->ensureCan($actor, 'stage_inputs.log_reject');

            return StageRejectLog::create([
                'order_id'          => $stage->order_id,
                'order_stage_id'    => $stage->id,
                'logged_by_user_id' => $actor?->id,
                'quantity_pcs'      => (int) $data['quantity_pcs'],
                'photo_path'        => $data['photo_path'] ?? null,
                'notes'             => $data['notes'] ?? null,
            ]);
        });

        $this->notifications->stageRejectLogged($log);
        return $log->fresh();
    }

    public function deleteRejectLog(int $logId, ?User $actor = null): void
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'stage_inputs.delete');

        $log = StageRejectLog::find($logId);
        if (! $log) {
            return;
        }

        $log->delete();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Load and validate the stage. Confirms it belongs to the claimed
     * order (defensive — payload could be tampered with) and that it's
     * in an active state.
     */
    protected function loadActiveStage(int $stageId, int $expectedOrderId): OrderStage
    {
        $stage = OrderStage::find($stageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage not found.',
            ]);
        }

        if ($stage->order_id !== $expectedOrderId) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage does not belong to that order.',
            ]);
        }

        $activeStatuses = [
            OrderStage::STATUS_IN_PROGRESS,
            OrderStage::STATUS_FOR_APPROVAL,
            OrderStage::STATUS_DELAYED,
        ];

        if (! in_array($stage->status, $activeStatuses, true)) {
            throw ValidationException::withMessages([
                'order_stage_id' => "Cannot log against a stage in status '{$stage->status}'. Stage must be active.",
            ]);
        }

        return $stage;
    }

    /**
     * Throws 403 if the actor lacks the permission. The route middleware
     * usually catches this first, but this is defence-in-depth for code
     * paths that bypass the controller (queue jobs, console commands).
     */
    protected function ensureCan(?User $actor, string $permission): void
    {
        if (! $actor) {
            throw ValidationException::withMessages([
                'actor' => 'No authenticated user.',
            ]);
        }

        if (! $actor->can($permission)) {
            throw ValidationException::withMessages([
                'permission' => "You do not have permission to {$permission}.",
            ]);
        }
    }
}
