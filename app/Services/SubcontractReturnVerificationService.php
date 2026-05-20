<?php

namespace App\Services;

use App\Models\StageSubcontractAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-I — Return-verification flow.
 *
 * Submitted when the Logistics user receives a return shipment from
 * the vendor and inspects it:
 *   - return_qty_received
 *   - return_condition_notes
 *   - return_photo_front_path / return_photo_back_path
 *
 * Submitting flips assignment.status to 'returned' and records the
 * verifier + timestamp.
 *
 * Permission model:
 *   This service uses `action.manage-subcontract` — the permission
 *   already granted to the `logistics` role in RbacSeeder. We do NOT
 *   reuse SubcontractService::markReturned() because that method
 *   requires `stage_inputs.log_subcontract`, which is intended for
 *   stage operators (sewer/cutter/printer) logging mid-production
 *   subcontract handoffs, NOT for Logistics staff verifying returns.
 *
 *   The state flip + notification side-effect are inlined here.
 */
class SubcontractReturnVerificationService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    /**
     * @param array{
     *   return_qty_received:int,
     *   return_condition_notes?:string|null,
     *   return_photo_front_path?:string|null,
     *   return_photo_back_path?:string|null,
     * } $data
     */
    public function verify(int $assignmentId, array $data, ?User $actor = null): StageSubcontractAssignment
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        $assignment = DB::transaction(function () use ($assignmentId, $data, $actor) {
            $a = StageSubcontractAssignment::lockForUpdate()->find($assignmentId);
            if (! $a) {
                throw ValidationException::withMessages(['id' => 'Assignment not found.']);
            }

            // Cannot verify a cancelled assignment.
            if ($a->status === StageSubcontractAssignment::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot verify return on a cancelled assignment.',
                ]);
            }

            // Cannot re-verify an already-verified return.
            if ($a->status === StageSubcontractAssignment::STATUS_RETURNED
                && $a->return_verified_at !== null) {
                throw ValidationException::withMessages([
                    'status' => 'This assignment has already been verified as returned.',
                ]);
            }

            $qty = (int) ($data['return_qty_received'] ?? 0);
            if ($qty < 0) {
                throw ValidationException::withMessages([
                    'return_qty_received' => 'Quantity received cannot be negative.',
                ]);
            }
            if ($qty > (int) $a->quantity_pcs) {
                throw ValidationException::withMessages([
                    'return_qty_received' =>
                        "Cannot receive more than the original quantity ({$a->quantity_pcs}).",
                ]);
            }

            // Single atomic update: verification fields + state flip.
            // Equivalent to markReturned()'s body but without the
            // stage_inputs.log_subcontract gate.
            $a->update([
                'return_qty_received'        => $qty,
                'return_condition_notes'     => $data['return_condition_notes']     ?? null,
                'return_photo_front_path'    => $data['return_photo_front_path']    ?? null,
                'return_photo_back_path'     => $data['return_photo_back_path']     ?? null,
                'return_verified_by_user_id' => $actor->id,
                'return_verified_at'         => now(),
                'status'                     => StageSubcontractAssignment::STATUS_RETURNED,
                'returned_at'                => $a->returned_at ?? now(),
            ]);

            return $a->fresh(['subcontractor']);
        });

        // Fire the same notification SubcontractService::markReturned()
        // would. Done outside the transaction so a notification failure
        // doesn't block the verification commit.
        try {
            $this->notifications->subcontractReturned($assignment);
        } catch (\Throwable $e) {
            // Notification failures are non-fatal — the verification is
            // already saved. Log and continue.
            report($e);
        }

        return $assignment;
    }

    protected function ensureCan(?User $actor): void
    {
        if (! $actor) {
            throw ValidationException::withMessages(['actor' => 'No authenticated user.']);
        }
        if (! $actor->can('action.manage-subcontract')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to verify subcontract returns.',
            ]);
        }
    }
}