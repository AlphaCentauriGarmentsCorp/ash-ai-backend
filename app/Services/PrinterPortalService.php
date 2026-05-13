<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderDesign;
use App\Models\OrderStage;
use App\Models\ScreenAssignment;
use App\Models\StageAuditLog;
use App\Models\StageInkLog;
use App\Models\StageSampleUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-C — Printer Portal data aggregator.
 *
 * Builds full portal context for one (order, stage) pair. Mirrors
 * CutterPortalService in shape, but with Printer-specific sections:
 *
 *   1. Order details (same as Cutter)
 *   2. Screen details — joined screen_assignments + screens + design placements
 *   3. Print placement guide — from order_design_placements
 *   4. Ink tracking (existing logs + totals, 3 decimal places)
 *   5. Material requests for this stage (Phase 3, same as Cutter)
 *   6. Sample uploads (shared with Cutter via stage_sample_uploads)
 *   7. Recent activity (last N audit log entries for this stage)
 */
class PrinterPortalService
{
    /**
     * Resolve the full Printer portal payload for a stage.
     *
     * @throws ValidationException if stage doesn't belong to a printing context
     */
    public function buildContext(int $orderStageId): array
    {
        $stage = OrderStage::find($orderStageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage not found.',
            ]);
        }

        $eligibleStages = ['sample_creation', 'mass_production'];
        if (! in_array($stage->stage, $eligibleStages, true)) {
            throw ValidationException::withMessages([
                'order_stage_id' => "Stage '{$stage->stage}' is not a printer portal stage.",
            ]);
        }

        $order = Order::find($stage->order_id);
        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => 'Order not found.',
            ]);
        }

        $phase = $stage->stage === 'sample_creation' ? 'sample' : 'mass';

        return [
            'order'            => $this->orderDetails($order),
            'stage'            => $this->stageContext($stage, $phase),
            'screen_details'   => $this->screenDetails($order),
            'print_placements' => $this->printPlacements($order),
            'ink_tracking'     => $this->inkTracking($stage),
            'material_requests'=> $this->materialRequestsForStage($stage),
            'sample_uploads'   => $this->sampleUploads($stage),
            'activity_log'     => $this->recentActivity($stage, 10),
        ];
    }

    // ── Section builders ────────────────────────────────────────────

    protected function orderDetails(Order $order): array
    {
        $items = $this->itemsAsArray($order);
        $totalPcs = 0;
        foreach ($items as $item) {
            $totalPcs += (int) ($item['quantity'] ?? 0);
        }

        return [
            'id'             => $order->id,
            'po_code'        => $order->po_code,
            'client_name'    => $order->client_name,
            'client_brand'   => $order->client_brand,
            'shirt_color'    => $order->shirt_color,
            'special_print'  => $order->special_print,
            'print_area'     => $order->print_area,
            'total_pcs'      => $totalPcs,
            'workflow_status'=> $order->workflow_status,
            'notes'          => $order->notes,
        ];
    }

    protected function stageContext(OrderStage $stage, string $phase): array
    {
        return [
            'id'           => $stage->id,
            'stage'        => $stage->stage,
            'sequence'     => $stage->sequence,
            'status'       => $stage->status,
            'phase'        => $phase,
            'started_at'   => $stage->started_at?->toDateTimeString(),
            'completed_at' => $stage->completed_at?->toDateTimeString(),
            'assigned_to'  => $stage->assigned_to,
            'notes'        => $stage->notes,
        ];
    }

    /**
     * Screen details — joins screen_assignments + screens + placements.
     * Each row tells the printer which screen to use for which placement.
     */
    protected function screenDetails(Order $order): array
    {
        $assignments = ScreenAssignment::with(['screen', 'placement'])
            ->where('order_id', $order->id)
            ->orderBy('color_index', 'asc')
            ->get();

        return $assignments->map(function ($a) {
            $screen = $a->screen;
            $placement = $a->placement;

            return [
                'id'              => $a->id,
                'color_index'     => $a->color_index,
                'placement_type'  => $placement?->type,           // "Front", "Back", "Left Chest", etc.
                'mockup_image'    => $placement?->mockup_image,
                'screen' => $screen ? [
                    'id'         => $screen->id,
                    'name'       => $screen->name,
                    'size'       => $screen->size,
                    'mesh_count' => $screen->mesh_count,
                    'address'    => $screen->address,
                    'status'     => $screen->status,
                ] : null,
            ];
        })->all();
    }

    /**
     * Print placement guide — t-shirt mockup areas with measurements.
     * Read from order_design_placements via the order's design.
     */
    protected function printPlacements(Order $order): array
    {
        $design = OrderDesign::where('order_id', $order->id)
            ->with('placements')
            ->first();

        if (! $design) {
            return [];
        }

        return $design->placements->map(function ($p) {
            // pantones is already an array per the OrderDesignPlacement cast.
            $pantones = is_array($p->pantones) ? $p->pantones : [];

            return [
                'id'           => $p->id,
                'type'         => $p->type,              // "Front", "Back", etc.
                'mockup_image' => $p->mockup_image,
                'mockup_url'   => $p->mockup_image
                    ? Storage::disk('public')->url($p->mockup_image)
                    : null,
                'pantones'     => $pantones,
            ];
        })->all();
    }

    /**
     * Ink logs for this stage + running totals (3 decimal precision).
     */
    protected function inkTracking(OrderStage $stage): array
    {
        $logs = StageInkLog::where('order_stage_id', $stage->id)
            ->with('loggedBy:id,name')
            ->orderBy('id', 'desc')
            ->get();

        $totalUsed  = (float) $logs->sum('ink_used_kg');
        $totalWaste = (float) $logs->sum('ink_waste_kg');

        return [
            'logs' => $logs->map(fn ($l) => [
                'id'                  => $l->id,
                'ink_color'           => $l->ink_color,
                'ink_used_kg'         => (float) $l->ink_used_kg,
                'ink_waste_kg'        => (float) $l->ink_waste_kg,
                'usable_remaining_kg' => (float) $l->usable_remaining_kg,
                'notes'               => $l->notes,
                'logged_by'           => $l->loggedBy ? [
                    'id'   => $l->loggedBy->id,
                    'name' => $l->loggedBy->name,
                ] : null,
                'created_at'          => $l->created_at?->toDateTimeString(),
            ])->all(),
            'totals' => [
                'ink_used_kg'         => round($totalUsed, 3),
                'ink_waste_kg'        => round($totalWaste, 3),
                'usable_remaining_kg' => round($totalUsed - $totalWaste, 3),
            ],
        ];
    }

    /**
     * Material requests for this stage (Phase 3).
     * IMPORTANT: Phase 3 uses `stage_id` not `order_stage_id`.
     */
    protected function materialRequestsForStage(OrderStage $stage): array
    {
        return MaterialRequest::where('stage_id', $stage->id)
            ->orderBy('id', 'desc')
            ->get(['id', 'mr_code', 'status', 'reason', 'approved_at', 'created_at'])
            ->map(fn ($mr) => [
                'id'          => $mr->id,
                'mr_code'     => $mr->mr_code,
                'status'      => $mr->status,
                'reason'      => $mr->reason,
                'approved_at' => $mr->approved_at?->toDateTimeString(),
                'created_at'  => $mr->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * Sample uploads for this stage.
     * Shared with Cutter portal — same table, same shape.
     */
    protected function sampleUploads(OrderStage $stage): array
    {
        return StageSampleUpload::where('order_stage_id', $stage->id)
            ->with('uploadedBy:id,name')
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn ($u) => [
                'id'                => $u->id,
                'photo_front_path'  => $u->photo_front_path,
                'photo_back_path'   => $u->photo_back_path,
                'photo_front_url'   => $u->photo_front_path
                    ? Storage::disk('public')->url($u->photo_front_path)
                    : null,
                'photo_back_url'    => $u->photo_back_path
                    ? Storage::disk('public')->url($u->photo_back_path)
                    : null,
                'remarks'           => $u->remarks,
                'sample_status'     => $u->sample_status,
                'completed_at'      => $u->completed_at?->toDateTimeString(),
                'uploaded_by'       => $u->uploadedBy ? [
                    'id'   => $u->uploadedBy->id,
                    'name' => $u->uploadedBy->name,
                ] : null,
                'created_at'        => $u->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    protected function recentActivity(OrderStage $stage, int $limit): array
    {
        return StageAuditLog::where('order_stage_id', $stage->id)
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get(['id', 'action', 'from_status', 'to_status', 'notes', 'user_id', 'created_at'])
            ->map(fn ($a) => [
                'id'          => $a->id,
                'action'      => $a->action,
                'from_status' => $a->from_status,
                'to_status'   => $a->to_status,
                'notes'       => $a->notes,
                'user_id'     => $a->user_id,
                'created_at'  => $a->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * Items_json defensively handled — array (cast), JSON string, or null.
     */
    protected function itemsAsArray(Order $order): array
    {
        $raw = $order->items_json;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
