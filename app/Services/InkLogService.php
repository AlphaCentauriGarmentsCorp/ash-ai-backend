<?php

namespace App\Services;

use App\Models\OrderStage;
use App\Models\StageInkLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-C — Manages stage_ink_logs writes for the Printer portal.
 *
 * Mirrors FabricLogService but for ink quantities (3 decimal places
 * instead of 2). usable_remaining_kg auto-computed at write time.
 */
class InkLogService
{
    /**
     * @param array{order_id:int,order_stage_id:int,ink_color?:string|null,ink_used_kg:float,ink_waste_kg?:float,notes?:string|null} $data
     */
    public function create(array $data, ?User $actor = null): StageInkLog
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        return DB::transaction(function () use ($data, $actor) {
            $stage = $this->loadActiveStage($data['order_stage_id'], $data['order_id']);

            $used  = (float) $data['ink_used_kg'];
            $waste = (float) ($data['ink_waste_kg'] ?? 0);

            if ($waste > $used) {
                throw ValidationException::withMessages([
                    'ink_waste_kg' => 'Waste cannot exceed ink used.',
                ]);
            }

            return StageInkLog::create([
                'order_id'            => $stage->order_id,
                'order_stage_id'      => $stage->id,
                'logged_by_user_id'   => $actor->id,
                'ink_color'           => $data['ink_color'] ?? null,
                'ink_used_kg'         => round($used, 3),
                'ink_waste_kg'        => round($waste, 3),
                'usable_remaining_kg' => round($used - $waste, 3),
                'notes'               => $data['notes'] ?? null,
            ]);
        });
    }

    public function delete(int $logId, ?User $actor = null): void
    {
        $actor = $actor ?? Auth::user();
        if (! $actor || ! $actor->can('stage_inputs.delete')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to delete ink logs.',
            ]);
        }

        $log = StageInkLog::find($logId);
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
                'permission' => 'You do not have permission to log ink usage.',
            ]);
        }
    }
}
