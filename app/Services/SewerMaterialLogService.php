<?php

namespace App\Services;

use App\Models\OrderStage;
use App\Models\StageFabricLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-E — Sewer-specific material logging.
 *
 * Writes to the same stage_fabric_logs table as Cutter but with a
 * required material_type tag. This lets Sewer track main fabric, rib/trim,
 * thread, interfacing, and waste separately while reusing the same
 * underlying schema and audit trail.
 */
class SewerMaterialLogService
{
    /**
     * @param array{
     *   order_id:int,
     *   order_stage_id:int,
     *   material_type:string,
     *   fabric_used_kg:float,
     *   waste_kg?:float,
     *   fabric_roll_id?:string|null,
     *   notes?:string|null,
     * } $data
     */
    public function create(array $data, ?User $actor = null): StageFabricLog
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);
        $this->ensureValidMaterialType($data['material_type']);

        return DB::transaction(function () use ($data, $actor) {
            $stage = $this->loadActiveStage($data['order_stage_id'], $data['order_id']);

            $used  = (float) $data['fabric_used_kg'];
            $waste = (float) ($data['waste_kg'] ?? 0);

            if ($waste > $used) {
                throw ValidationException::withMessages([
                    'waste_kg' => 'Waste cannot exceed amount used.',
                ]);
            }

            return StageFabricLog::create([
                'order_id'            => $stage->order_id,
                'order_stage_id'      => $stage->id,
                'logged_by_user_id'   => $actor->id,
                'material_type'       => $data['material_type'],
                'fabric_used_kg'      => round($used, 2),
                'waste_kg'            => round($waste, 2),
                'usable_remaining_kg' => round($used - $waste, 2),
                'fabric_roll_id'      => $data['fabric_roll_id'] ?? null,
                'notes'               => $data['notes'] ?? null,
            ]);
        });
    }

    public function delete(int $logId, ?User $actor = null): void
    {
        $actor = $actor ?? Auth::user();
        if (! $actor || ! $actor->can('stage_inputs.delete')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to delete material logs.',
            ]);
        }

        $log = StageFabricLog::find($logId);
        if ($log) {
            $log->delete();
        }
    }

    // ── Helpers ─────────────────────────────────────────────────

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
                'order_stage_id' => "Cannot log against a stage in status '{$stage->status}'.",
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

        if (! $actor->can('stage_inputs.log_waste')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to log material usage.',
            ]);
        }
    }

    protected function ensureValidMaterialType(string $type): void
    {
        if (! in_array($type, SewerPortalService::MATERIAL_TYPES, true)) {
            throw ValidationException::withMessages([
                'material_type' => "Invalid material type: {$type}",
            ]);
        }
    }
}
