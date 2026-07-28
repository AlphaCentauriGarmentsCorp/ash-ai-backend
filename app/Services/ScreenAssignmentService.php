<?php

namespace App\Services;

use App\Models\OrderDesignPlacement;
use App\Models\OrderStage;
use App\Models\ScreenAssignment;
use App\Models\Screens;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * SM Rework CP2 — Screen Maker's "Screens Used" write path.
 *
 * Replaces the legacy ScreenMakingService (POST /screen-making), which
 * force-completed the screen_making stage as a side effect and never
 * touched screen inventory at all. This service:
 *
 *   - writes one screen_assignments row per (order, placement,
 *     color_index) slot — the SAME table the Printer Portal
 *     (PrinterPortalService::screenDetails) and the CSR Review Hub
 *     already read from, so neither needed any change
 *   - drives the Screens Inventory status lifecycle:
 *       available   -> in_use        (screen picked for a slot)
 *       in_use      -> for_reclaim   (mass_printing stage completes —
 *                                     see OrderStagesService::markComplete)
 *       for_reclaim -> available     (manual, in Screen Inventory, once
 *                                     the screen has actually been
 *                                     stripped and washed)
 *   - increments total_use once per NEW assignment row only — not on
 *     re-saves of the same screen, not on swaps (Josh's call, 2026-07-28)
 *   - hard-blocks 'damaged' and 'for_reclaim' screens (a dirty or
 *     out-of-service screen can't be picked); a screen already 'in_use'
 *     on a DIFFERENT order is allowed with a warning in the response —
 *     not blocked (Josh's call: the screen maker knows the floor)
 *
 * Eligible stage: 'screen_making' only, same as the portal it belongs to.
 */
class ScreenAssignmentService
{
    /**
     * @param array{order_stage_id:int,placement_id:int,color_index:int,screen_id:int} $data
     * @return array{assignment: ScreenAssignment, conflict: array|null}
     */
    public function assign(array $data, ?User $actor = null): array
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        return DB::transaction(function () use ($data) {
            $stage = $this->loadActiveStage((int) $data['order_stage_id']);

            $placement = OrderDesignPlacement::find($data['placement_id']);
            if (! $placement) {
                throw ValidationException::withMessages([
                    'placement_id' => 'Placement not found.',
                ]);
            }

            $colorIndex = (int) $data['color_index'];
            if ($placement->color_count && $colorIndex > (int) $placement->color_count) {
                throw ValidationException::withMessages([
                    'color_index' => "This placement only has {$placement->color_count} colour(s).",
                ]);
            }

            $screen = Screens::find($data['screen_id']);
            if (! $screen) {
                throw ValidationException::withMessages([
                    'screen_id' => 'Screen not found.',
                ]);
            }

            if (in_array($screen->status, ['damaged', 'for_reclaim'], true)) {
                $reason = $screen->status === 'damaged'
                    ? 'is marked damaged'
                    : 'still needs to be stripped and washed before it can be reused';
                throw ValidationException::withMessages([
                    'screen_id' => "This screen {$reason}.",
                ]);
            }

            $existing = ScreenAssignment::where('order_id', $stage->order_id)
                ->where('placement_id', $placement->id)
                ->where('color_index', $colorIndex)
                ->first();

            $previousScreenId = $existing?->screen_id;

            $assignment = ScreenAssignment::updateOrCreate(
                [
                    'order_id'     => $stage->order_id,
                    'placement_id' => $placement->id,
                    'color_index'  => $colorIndex,
                ],
                [
                    'screen_id' => $screen->id,
                ]
            );

            $screenChanged = $previousScreenId !== $screen->id;

            // total_use: only the FIRST time a slot picks ANY screen — not
            // on a re-save of the same choice, not on a swap. wasRecentlyCreated
            // reflects whether the (order, placement, color_index) ROW is new,
            // not whether screen_id changed — so a swap (existing slot, new
            // screen_id) leaves it false and total_use untouched for the
            // swapped-in screen too. This is deliberate, not a gap: Josh's
            // call was "once per new assignment row, skip re-saves/swaps".
            if ($assignment->wasRecentlyCreated) {
                $screen->increment('total_use');
            }

            // Claim the screen for this slot (new slot OR swapped screen).
            if (! $existing || $screenChanged) {
                $screen->forceFill([
                    'status'    => 'in_use',
                    'last_used' => now(),
                ])->save();
            }

            // Swap: free the screen this slot no longer uses, as long as
            // nothing else currently holds it.
            if ($existing && $screenChanged && $previousScreenId) {
                $this->releaseScreenIfUnreferenced((int) $previousScreenId);
            }

            return [
                'assignment' => $assignment->fresh(['screen', 'placement']),
                'conflict'   => $this->findConflict($screen, $stage->order_id),
            ];
        });
    }

    /**
     * Clear a slot (screen maker picked the wrong screen and wants it
     * blank again, pre-save). Frees the screen if nothing else holds it.
     */
    public function unassign(int $assignmentId, ?User $actor = null): void
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        DB::transaction(function () use ($assignmentId) {
            $assignment = ScreenAssignment::find($assignmentId);
            if (! $assignment) {
                return;
            }

            $screenId = $assignment->screen_id;
            $assignment->delete();
            $this->releaseScreenIfUnreferenced((int) $screenId);
        });
    }

    /**
     * mass_printing done -> every screen this order is still holding
     * moves to 'for_reclaim' (dirty, needs stripping/washing before its
     * next job). Called from OrderStagesService::markComplete.
     *
     * Guarded with Schema::hasTable so the many markComplete() Pest
     * suites that don't build the screens/screen_assignments tables are
     * unaffected — same guard pattern as
     * ScreenMakerPortalService::colorHex() uses for fabric_swatches.
     */
    public function releaseForOrder(int $orderId): void
    {
        if (! Schema::hasTable('screen_assignments') || ! Schema::hasTable('screens')) {
            return;
        }

        $screenIds = ScreenAssignment::where('order_id', $orderId)
            ->pluck('screen_id')
            ->unique()
            ->values();

        if ($screenIds->isEmpty()) {
            return;
        }

        Screens::whereIn('id', $screenIds)
            ->where('status', 'in_use')
            ->update(['status' => 'for_reclaim']);
    }

    // ── Helpers ────────────────────────────────────────────────────

    protected function releaseScreenIfUnreferenced(int $screenId): void
    {
        $stillHeld = ScreenAssignment::where('screen_id', $screenId)->exists();
        if ($stillHeld) {
            return;
        }

        Screens::where('id', $screenId)
            ->where('status', 'in_use')
            ->update(['status' => 'available']);
    }

    /**
     * Warn-but-allow: if the screen is currently 'in_use' and its most
     * recent holder is a DIFFERENT order, surface that so the portal can
     * show a warning banner. Never blocks the save.
     */
    protected function findConflict(Screens $screen, int $orderId): ?array
    {
        if ($screen->status !== 'in_use') {
            return null;
        }

        $holder = ScreenAssignment::where('screen_id', $screen->id)
            ->where('order_id', '!=', $orderId)
            ->with('order:id,po_code,client_name')
            ->orderBy('id', 'desc')
            ->first();

        if (! $holder || ! $holder->order) {
            return null;
        }

        return [
            'order_id'    => $holder->order->id,
            'po_code'     => $holder->order->po_code,
            'client_name' => $holder->order->client_name,
        ];
    }

    protected function loadActiveStage(int $stageId): OrderStage
    {
        $stage = OrderStage::find($stageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage not found.',
            ]);
        }

        if ($stage->stage !== 'screen_making') {
            throw ValidationException::withMessages([
                'order_stage_id' => "Stage '{$stage->stage}' is not a screen maker portal stage.",
            ]);
        }

        $activeStatuses = [
            OrderStage::STATUS_IN_PROGRESS,
            OrderStage::STATUS_FOR_APPROVAL,
            OrderStage::STATUS_DELAYED,
        ];

        if (! in_array($stage->status, $activeStatuses, true)) {
            throw ValidationException::withMessages([
                'order_stage_id' => "Cannot assign screens on a stage in status '{$stage->status}'.",
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

        // Reuses the existing access.screen-making permission — the same
        // activity the legacy POST /screen-making endpoint gated on.
        if (! $actor->can('access.screen-making')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to assign screens.',
            ]);
        }
    }
}
