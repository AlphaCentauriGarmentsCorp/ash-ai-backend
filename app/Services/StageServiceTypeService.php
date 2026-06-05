<?php

namespace App\Services;

use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Models\StageSubcontractAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-D — Switches a stage between in-house and subcontract.
 *
 * Eligible stages (flippable):
 *   - sample_cutting / sample_printing / sample_sewing
 *   - mass_cutting / mass_printing / mass_sewing
 *   - screen_making
 *   - mass_qa
 *   - sample_packing / mass_packing
 *
 * Other stages (inquiry, quotation, payment_verification, delivery, etc.)
 * are NOT flippable — they're either intrinsically in-house (CSR work)
 * or intrinsically subcontract (delivery via courier).
 *
 * Switch behavior:
 *   - in_house → subcontract:
 *       - Clear OrderStage.assigned_to (in-house assignment voided)
 *       - Preserve any existing fabric/ink/sample logs as audit trail
 *       - Audit log entry: service_type_changed
 *
 *   - subcontract → in_house:
 *       - Cancel any active stage_subcontract_assignment row
 *         (status not in [returned, cancelled])
 *       - Don't auto-assign a new user; admin picks one separately
 *       - Audit log entry: service_type_changed
 *
 *   - Same → same: no-op, returns the stage unchanged
 *
 * Both transitions write an audit log entry with old/new values in
 * the `notes` column so the change is traceable.
 */
class StageServiceTypeService
{
    /**
     * Stages where in-house vs subcontract makes sense.
     */
    public const FLIPPABLE_STAGES = [
        'sample_cutting',
        'sample_printing',
        'sample_sewing',
        'mass_cutting',
        'mass_printing',
        'mass_sewing',
        'screen_making',
        'mass_qa',
        'sample_packing',
        'mass_packing',
    ];

    /**
     * Switch a stage's service type.
     *
     * @param int    $stageId
     * @param string $newType    'in_house' or 'subcontract'
     * @param User|null $actor   defaults to Auth::user()
     * @param string|null $reason  optional manager-supplied reason
     *
     * @return OrderStage refreshed
     *
     * @throws ValidationException on bad input / permission denial
     */
    public function switch(int $stageId, string $newType, ?User $actor = null, ?string $reason = null): OrderStage
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);
        $this->ensureValidType($newType);

        return DB::transaction(function () use ($stageId, $newType, $actor, $reason) {
            $stage = OrderStage::lockForUpdate()->find($stageId);
            if (! $stage) {
                throw ValidationException::withMessages([
                    'order_stage_id' => 'Stage not found.',
                ]);
            }

            $this->ensureFlippable($stage);

            $oldType = $stage->service_type ?? OrderStage::SERVICE_IN_HOUSE;

            // No-op if already in the desired state.
            if ($oldType === $newType) {
                return $stage;
            }

            // Apply cascade behavior based on direction.
            if ($newType === OrderStage::SERVICE_SUBCONTRACT) {
                $this->cascadeToSubcontract($stage);
            } else {
                $this->cascadeToInHouse($stage);
            }

            // Flip the stage itself.
            $stage->update([
                'service_type' => $newType,
            ]);

            // Audit entry.
            $this->writeAuditLog($stage, $oldType, $newType, $actor, $reason);

            return $stage->fresh();
        });
    }

    // ── Cascade handlers ────────────────────────────────────────

    /**
     * Going in_house → subcontract.
     * - Void any in-house user assignment
     * - Preserve existing fabric/ink/sample logs (audit trail)
     */
    protected function cascadeToSubcontract(OrderStage $stage): void
    {
        if ($stage->assigned_to !== null) {
            $stage->update(['assigned_to' => null]);
        }
        // Do NOT touch logs/uploads — they remain for historical reference.
    }

    /**
     * Going subcontract → in_house.
     * - Cancel any active stage_subcontract_assignment
     */
    protected function cascadeToInHouse(OrderStage $stage): void
    {
        $activeAssignments = StageSubcontractAssignment::where('order_stage_id', $stage->id)
            ->whereNotIn('status', ['returned', 'cancelled'])
            ->get();

        foreach ($activeAssignments as $assignment) {
            $assignment->update(['status' => 'cancelled']);
        }
    }

    // ── Audit logging ───────────────────────────────────────────

    protected function writeAuditLog(
        OrderStage $stage,
        string $oldType,
        string $newType,
        User $actor,
        ?string $reason,
    ): void {
        $notes = "Service type changed: {$oldType} → {$newType}";
        if ($reason !== null && trim($reason) !== '') {
            $notes .= ' — ' . trim($reason);
        }

        StageAuditLog::create([
            'order_id'       => $stage->order_id,
            'order_stage_id' => $stage->id,
            'user_id'        => $actor->id,
            'action'         => 'service_type_changed',
            'from_status'    => $oldType,         // reusing fields for old/new
            'to_status'      => $newType,
            'notes'          => $notes,
            'created_at'     => now(),
        ]);
    }

    // ── Guards ──────────────────────────────────────────────────

    protected function ensureCan(?User $actor): void
    {
        if (! $actor) {
            throw ValidationException::withMessages([
                'actor' => 'No authenticated user.',
            ]);
        }

        if (! $actor->can('action.switch-service-type')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to change service type.',
            ]);
        }
    }

    protected function ensureValidType(string $newType): void
    {
        if (! in_array($newType, OrderStage::allServiceTypes(), true)) {
            throw ValidationException::withMessages([
                'service_type' => "Invalid service type: {$newType}",
            ]);
        }
    }

    protected function ensureFlippable(OrderStage $stage): void
    {
        if (! in_array($stage->stage, self::FLIPPABLE_STAGES, true)) {
            throw ValidationException::withMessages([
                'stage' => "Stage '{$stage->stage}' is not flippable between in-house and subcontract.",
            ]);
        }

        // Can't flip a completed stage — its work is already done one way.
        if ($stage->status === OrderStage::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => 'Cannot change service type of a completed stage.',
            ]);
        }
    }
}
