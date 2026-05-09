<?php

namespace App\Services;

use App\Models\OrderStage;
use App\Models\SewingSubcontractor;
use App\Models\StageSubcontractAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 4 — Subcontract assignment lifecycle.
 *
 * State machine:
 *   pending → out → returned
 *   pending → cancelled
 *   out → cancelled
 *   returned → (terminal)
 *
 * Notifications fire on assign + return (not on send/cancel — those
 * are operational events, not status-of-the-shop events).
 */
class SubcontractService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Create a new subcontract assignment for a stage.
     *
     * Snapshots the vendor's current rate at assignment time so the
     * total stays correct even if the rate is updated later.
     *
     * @param array{order_id:int,order_stage_id:int,subcontractor_id:int,quantity_pcs:int,notes?:string|null} $data
     */
    public function assign(array $data, ?User $actor = null): StageSubcontractAssignment
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'stage_inputs.log_subcontract');

        $assignment = DB::transaction(function () use ($data) {
            $stage = OrderStage::find($data['order_stage_id']);
            if (! $stage) {
                throw ValidationException::withMessages([
                    'order_stage_id' => 'Stage not found.',
                ]);
            }

            if ($stage->order_id !== (int) $data['order_id']) {
                throw ValidationException::withMessages([
                    'order_stage_id' => 'Stage does not belong to that order.',
                ]);
            }

            $vendor = SewingSubcontractor::find($data['subcontractor_id']);
            if (! $vendor) {
                throw ValidationException::withMessages([
                    'subcontractor_id' => 'Subcontractor not found.',
                ]);
            }

            $qty  = (int) $data['quantity_pcs'];
            $rate = (float) ($vendor->rate_per_pcs ?? 0);

            return StageSubcontractAssignment::create([
                'order_id'         => $stage->order_id,
                'order_stage_id'   => $stage->id,
                'subcontractor_id' => $vendor->id,
                'quantity_pcs'     => $qty,
                'rate_per_pcs'     => $rate,
                'total_amount'     => round($qty * $rate, 2),
                'status'           => StageSubcontractAssignment::STATUS_PENDING,
                'notes'            => $data['notes'] ?? null,
            ]);
        });

        $this->notifications->subcontractAssigned($assignment);
        return $assignment->fresh(['subcontractor']);
    }

    /**
     * pending → out (vendor has been physically shipped the work).
     */
    public function markSent(int $assignmentId, ?User $actor = null): StageSubcontractAssignment
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'stage_inputs.log_subcontract');

        return DB::transaction(function () use ($assignmentId) {
            $assignment = StageSubcontractAssignment::lockForUpdate()->findOrFail($assignmentId);

            if (! $assignment->isPending()) {
                throw ValidationException::withMessages([
                    'status' => "Cannot mark sent: assignment is in status '{$assignment->status}'.",
                ]);
            }

            $assignment->update([
                'status'  => StageSubcontractAssignment::STATUS_OUT,
                'sent_at' => now(),
            ]);

            return $assignment->fresh(['subcontractor']);
        });
    }

    /**
     * out → returned (vendor has returned the goods; QA can inspect).
     */
    public function markReturned(int $assignmentId, ?User $actor = null): StageSubcontractAssignment
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'stage_inputs.log_subcontract');

        $assignment = DB::transaction(function () use ($assignmentId) {
            $a = StageSubcontractAssignment::lockForUpdate()->findOrFail($assignmentId);

            if (! $a->isOut()) {
                throw ValidationException::withMessages([
                    'status' => "Cannot mark returned: assignment is in status '{$a->status}'.",
                ]);
            }

            $a->update([
                'status'      => StageSubcontractAssignment::STATUS_RETURNED,
                'returned_at' => now(),
            ]);

            return $a->fresh(['subcontractor']);
        });

        $this->notifications->subcontractReturned($assignment);
        return $assignment;
    }

    /**
     * Cancel an assignment. Allowed from pending or out (e.g., wrong
     * vendor picked, or vendor couldn't deliver and we're recalling
     * the work). Not allowed once it's already returned.
     */
    public function cancel(int $assignmentId, ?User $actor = null): StageSubcontractAssignment
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'stage_inputs.log_subcontract');

        return DB::transaction(function () use ($assignmentId) {
            $assignment = StageSubcontractAssignment::lockForUpdate()->findOrFail($assignmentId);

            if ($assignment->isReturned() || $assignment->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => "Cannot cancel: assignment is already in status '{$assignment->status}'.",
                ]);
            }

            $assignment->update([
                'status' => StageSubcontractAssignment::STATUS_CANCELLED,
            ]);

            return $assignment->fresh(['subcontractor']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────

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
