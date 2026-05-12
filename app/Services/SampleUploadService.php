<?php

namespace App\Services;

use App\Models\OrderStage;
use App\Models\StageSampleUpload;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-B — Stage sample upload management.
 *
 * Used by the Cutter portal (and later Printer / Sewer / Sample Maker
 * portals — all 4 production roles upload sample photos through this
 * same service). Photo paths come in pre-stored (HTTP layer handles
 * the actual file upload to public disk).
 *
 * Lifecycle: pending → for_approval → approved | rejected
 *  - 'pending' means user has started but not finalized.
 *  - 'for_approval' means user pressed "Mark as Done" — sample is ready
 *    for the GM/CSR to inspect.
 *  - 'approved' / 'rejected' are terminal states set by an approver
 *    (handled in a future Sample Approval portal / endpoint).
 */
class SampleUploadService
{
    /**
     * Create a new sample upload record.
     *
     * @param array{
     *   order_id:int,
     *   order_stage_id:int,
     *   photo_front_path?:string|null,
     *   photo_back_path?:string|null,
     *   remarks?:string|null,
     *   sample_status?:string,
     * } $data
     */
    public function create(array $data, ?User $actor = null): StageSampleUpload
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        return DB::transaction(function () use ($data, $actor) {
            $stage = $this->loadActiveStage($data['order_stage_id'], $data['order_id']);

            $status = $data['sample_status'] ?? StageSampleUpload::STATUS_FOR_APPROVAL;

            return StageSampleUpload::create([
                'order_id'             => $stage->order_id,
                'order_stage_id'       => $stage->id,
                'uploaded_by_user_id'  => $actor->id,
                'photo_front_path'     => $data['photo_front_path'] ?? null,
                'photo_back_path'      => $data['photo_back_path'] ?? null,
                'remarks'              => $data['remarks'] ?? null,
                'sample_status'        => $status,
                'completed_at'         => $status === StageSampleUpload::STATUS_FOR_APPROVAL
                    ? now()
                    : null,
            ]);
        });
    }

    /**
     * Update an existing sample upload (re-uploads, status changes).
     *
     * @param array{
     *   photo_front_path?:string|null,
     *   photo_back_path?:string|null,
     *   remarks?:string|null,
     *   sample_status?:string,
     * } $data
     */
    public function update(int $uploadId, array $data, ?User $actor = null): StageSampleUpload
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        return DB::transaction(function () use ($uploadId, $data, $actor) {
            $upload = StageSampleUpload::lockForUpdate()->findOrFail($uploadId);

            $patch = [];
            foreach (['photo_front_path', 'photo_back_path', 'remarks', 'sample_status'] as $field) {
                if (array_key_exists($field, $data)) {
                    $patch[$field] = $data[$field];
                }
            }

            // If transitioning to for_approval, set completed_at if not set.
            if (
                ($patch['sample_status'] ?? null) === StageSampleUpload::STATUS_FOR_APPROVAL
                && ! $upload->completed_at
            ) {
                $patch['completed_at'] = now();
            }

            $upload->update($patch);
            return $upload->fresh();
        });
    }

    /**
     * Hard-delete a sample upload (managers only).
     */
    public function delete(int $uploadId, ?User $actor = null): void
    {
        $actor = $actor ?? Auth::user();
        if (! $actor || ! $actor->can('stage_inputs.delete')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to delete sample uploads.',
            ]);
        }

        $upload = StageSampleUpload::find($uploadId);
        if ($upload) {
            $upload->delete();
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

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
                'order_stage_id' => "Cannot upload against a stage in status '{$stage->status}'.",
            ]);
        }

        return $stage;
    }

    protected function ensureCan(?User $actor): void
    {
        if (! $actor) {
            throw ValidationException::withMessages([
                'actor' => 'No authenticated user.',
            ]);
        }

        // Reuses upload-photos permission (already seeded for production roles).
        if (! $actor->can('action.upload-photos')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to upload sample photos.',
            ]);
        }
    }
}
