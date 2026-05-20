<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderDesign;
use App\Models\OrderStage;
use App\Models\ScreenAssignment;
use App\Models\StageAuditLog;
use App\Models\StageSubcontractAssignment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-F — Screen Maker Portal data aggregator.
 *
 * Screen Maker is mostly a READ-ONLY portal. Their job:
 *   1. See which designs need screens (from order_designs + placements)
 *   2. See which physical screens are mapped (from screen_assignments)
 *   3. Make the screens, then mark the stage as done
 *
 * Sections (per Screen_Maker.png mockup):
 *   1. Order Details (reuse shared component)
 *   2. Designs to Make Screen — per-placement design info + screen mapping
 *   3. Screen Used — same data, table layout
 *   4. Notes — stage notes (handled via existing OrderStagesController::note)
 *   5. Material Requests — reuse Phase 3 integration
 *   6. Activity Log — reuse
 *
 * Eligible stage: 'screen_making' only.
 */
class ScreenMakerPortalService
{
    public function buildContext(int $orderStageId): array
    {
        $stage = OrderStage::find($orderStageId);
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

        $order = Order::find($stage->order_id);
        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => 'Order not found.',
            ]);
        }

        return [
            'order'             => $this->orderDetails($order),
            'stage'             => $this->stageContext($stage),
            'designs'           => $this->designsToMakeScreen($order),
            'material_requests' => $this->materialRequestsForStage($stage),
            'activity_log'      => $this->recentActivity($stage, 10),
            'subcontract'       => $this->subcontractInfo($stage),
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

    protected function stageContext(OrderStage $stage): array
    {
        return [
            'id'           => $stage->id,
            'stage'        => $stage->stage,
            'sequence'     => $stage->sequence,
            'status'       => $stage->status,
            'service_type' => $stage->service_type ?? OrderStage::SERVICE_IN_HOUSE,
            'started_at'   => $stage->started_at?->toDateTimeString(),
            'completed_at' => $stage->completed_at?->toDateTimeString(),
            'assigned_to'  => $stage->assigned_to,
            'notes'        => $stage->notes,
        ];
    }

    /**
     * Designs that need screens for this order.
     *
     * Joins:
     *   order_designs → order_design_placements (the design definitions)
     *   screen_assignments → screens (the physical screen mappings)
     *
     * Each placement might have multiple screen_assignments (one per
     * ink color / color_index). We return placements with their nested
     * screen list so the UI can show "Front - 3 colors using S-001, S-002, S-003".
     */
    protected function designsToMakeScreen(Order $order): array
    {
        $design = OrderDesign::where('order_id', $order->id)
            ->with('placements')
            ->first();

        if (! $design) {
            return [];
        }

        $assignments = ScreenAssignment::with('screen')
            ->where('order_id', $order->id)
            ->get()
            ->groupBy('placement_id');

        return $design->placements->map(function ($p) use ($assignments) {
            $placementAssignments = $assignments->get($p->id, collect());

            return [
                'id'             => $p->id,
                'type'           => $p->type,             // "Front", "Back", "Left Chest", etc.
                'mockup_image'   => $p->mockup_image,
                'mockup_url'     => $p->mockup_image
                    ? Storage::disk('public')->url($p->mockup_image)
                    : null,
                'pantones'       => is_array($p->pantones) ? $p->pantones : [],
                'screens'        => $placementAssignments->map(fn ($a) => [
                    'assignment_id' => $a->id,
                    'color_index'   => $a->color_index,
                    'screen' => $a->screen ? [
                        'id'         => $a->screen->id,
                        'name'       => $a->screen->name,
                        'size'       => $a->screen->size,
                        'mesh_count' => $a->screen->mesh_count,
                        'address'    => $a->screen->address,
                        'status'     => $a->screen->status,
                    ] : null,
                ])->all(),
            ];
        })->all();
    }

    /**
     * Material requests — Phase 3 uses stage_id (not order_stage_id).
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
     * Subcontract info — surfaces vendor when stage is outsourced.
     * Same shape as Cutter/Printer/Sewer so SubcontractModeView renders it.
     */
    protected function subcontractInfo(OrderStage $stage): ?array
    {
        if ($stage->service_type !== OrderStage::SERVICE_SUBCONTRACT) {
            return null;
        }

        $assignment = StageSubcontractAssignment::with('subcontractor')
            ->where('order_stage_id', $stage->id)
            ->whereNotIn('status', ['returned', 'cancelled'])
            ->orderBy('id', 'desc')
            ->first();

        if (! $assignment) {
            return [
                'has_assignment' => false,
                'message'        => 'Stage is set to subcontract but no vendor has been assigned yet.',
            ];
        }

        $vendor = $assignment->subcontractor;

        return [
            'has_assignment'        => true,
            'id'                    => $assignment->id,
            'status'                => $assignment->status,
            'sent_at'               => $assignment->sent_at?->toDateTimeString(),
            'returned_at'           => $assignment->returned_at?->toDateTimeString(),
            'expected_return_at'    => $assignment->expected_return_at?->toDateTimeString(),
            'turnover_method'       => $assignment->turnover_method,
            'quantity_pcs'          => (int) $assignment->quantity_pcs,
            'rate_per_pcs'          => (float) $assignment->rate_per_pcs,
            'total_amount'          => (float) $assignment->total_amount,
            'payment_terms'         => $assignment->payment_terms,
            'waybill_number'        => $assignment->waybill_number,
            'gc_chat_link'          => $assignment->gc_chat_link,
            'vendor_contact_number' => $assignment->vendor_contact_number,
            'notes'                 => $assignment->notes,
            'vendor' => $vendor ? [
                'id'   => $vendor->id,
                'name' => $vendor->name ?? null,
            ] : null,
        ];
    }

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
