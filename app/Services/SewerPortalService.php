<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderDesign;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Models\StageFabricLog;
use App\Models\StageSampleUpload;
use App\Models\StageSubcontractAssignment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-E — Sewer Portal data aggregator.
 *
 * Sewer is the most subcontract-heavy portal — most sewing is outsourced.
 * Service returns both in-house tracking data AND subcontract info; the
 * frontend branches based on stage.service_type.
 *
 * Sections (per Sewer.png mockup):
 *   1. Order Details (reuse from shared OrderDetailsSection)
 *   2. Sample Details — design/style, sample type, size, fabric, notes from GA
 *   3. Measurements & Reference — size chart + pattern layout
 *   4. Subcontract Details — when outsourced (uses SubcontractModeView)
 *   5. Subcontract Tracking — same component
 *   6. Materials & Usage — multi-material tracking (main fabric, rib/trim, thread, etc.)
 *   7. Request Material / Items (reuse from shared MaterialRequestsSection)
 *   8. Sample Output & Upload (Sewer-specific component)
 */
class SewerPortalService
{
    /**
     * Material types Sewer can log usage against.
     */
    public const MATERIAL_TYPES = [
        'main_fabric',
        'rib_trim',
        'thread',
        'interfacing',
        'other',
        'waste',
    ];

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
                'order_stage_id' => "Stage '{$stage->stage}' is not a sewer portal stage.",
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
            'order'              => $this->orderDetails($order),
            'stage'              => $this->stageContext($stage, $phase),
            'sample_details'     => $this->sampleDetails($order),
            'measurements'       => $this->measurements($order),
            'materials_usage'    => $this->materialsUsage($stage),
            'material_requests'  => $this->materialRequestsForStage($stage),
            'sample_uploads'     => $this->sampleUploads($stage),
            'activity_log'       => $this->recentActivity($stage, 10),
            'subcontract'        => $this->subcontractInfo($stage),
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
            'service_type' => $stage->service_type ?? OrderStage::SERVICE_IN_HOUSE,
            'started_at'   => $stage->started_at?->toDateTimeString(),
            'completed_at' => $stage->completed_at?->toDateTimeString(),
            'assigned_to'  => $stage->assigned_to,
            'notes'        => $stage->notes,
        ];
    }

    /**
     * Sample details — design/style, fabric, sample type, GA notes.
     * Pulled from order + design records.
     */
    protected function sampleDetails(Order $order): array
    {
        $design = OrderDesign::where('order_id', $order->id)->first();

        return [
            'design_notes'    => $design?->notes,
            'design_size_label' => $design?->size_label,
            // Sample-specific fields from the order itself
            'shirt_color'     => $order->shirt_color,
            'special_print'   => $order->special_print,
            'print_area'      => $order->print_area,
            // Items_json carries the size info but we surface a primary
            // size summary for the sample portal.
            'sizes'           => collect($this->itemsAsArray($order))
                ->pluck('size')
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * Measurements + reference layout. We don't have a dedicated size
     * chart table — the per-order sizes live in items_json. Pattern
     * reference image comes from the design record if present.
     */
    protected function measurements(Order $order): array
    {
        $design = OrderDesign::where('order_id', $order->id)
            ->with('placements')
            ->first();

        $sizes = collect($this->itemsAsArray($order))
            ->map(fn ($i) => [
                'size'     => $i['size'] ?? null,
                'quantity' => (int) ($i['quantity'] ?? 0),
            ])
            ->filter(fn ($i) => $i['size'] !== null)
            ->values()
            ->all();

        $referencePlacements = $design
            ? $design->placements->map(fn ($p) => [
                'id'           => $p->id,
                'type'         => $p->type,
                'mockup_image' => $p->mockup_image,
                'mockup_url'   => $p->mockup_image
                    ? Storage::disk('public')->url($p->mockup_image)
                    : null,
            ])->all()
            : [];

        return [
            'sizes'                => $sizes,
            'reference_placements' => $referencePlacements,
        ];
    }

    /**
     * Materials & Usage — multi-material breakdown.
     *
     * Pulls all stage_fabric_logs rows for this stage, groups by
     * material_type, returns per-material totals + the raw rows.
     */
    protected function materialsUsage(OrderStage $stage): array
    {
        $logs = StageFabricLog::where('order_stage_id', $stage->id)
            ->with('loggedBy:id,name')
            ->orderBy('id', 'desc')
            ->get();

        // Per-material totals
        $byType = $logs->groupBy(fn ($l) => $l->material_type ?? 'unspecified')
            ->map(fn ($group, $type) => [
                'material_type' => $type,
                'used_kg'       => round((float) $group->sum('fabric_used_kg'), 2),
                'waste_kg'      => round((float) $group->sum('waste_kg'), 2),
                'remaining_kg'  => round(
                    (float) $group->sum('fabric_used_kg')
                    - (float) $group->sum('waste_kg'),
                    2
                ),
                'entry_count'   => $group->count(),
            ])
            ->values()
            ->all();

        // Grand totals across all materials
        $totalUsed  = (float) $logs->sum('fabric_used_kg');
        $totalWaste = (float) $logs->sum('waste_kg');

        return [
            'logs' => $logs->map(fn ($l) => [
                'id'                  => $l->id,
                'material_type'       => $l->material_type,
                'fabric_used_kg'      => (float) $l->fabric_used_kg,
                'waste_kg'            => (float) $l->waste_kg,
                'usable_remaining_kg' => (float) $l->usable_remaining_kg,
                'fabric_roll_id'      => $l->fabric_roll_id,
                'notes'               => $l->notes,
                'logged_by'           => $l->loggedBy ? [
                    'id'   => $l->loggedBy->id,
                    'name' => $l->loggedBy->name,
                ] : null,
                'created_at'          => $l->created_at?->toDateTimeString(),
            ])->all(),
            'by_material' => $byType,
            'grand_totals' => [
                'used_kg'  => round($totalUsed, 2),
                'waste_kg' => round($totalWaste, 2),
                'remaining_kg' => round($totalUsed - $totalWaste, 2),
            ],
        ];
    }

    /**
     * Material requests — uses Phase 3's `stage_id` column.
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
     * Sample uploads — shared with Cutter/Printer.
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
     * Subcontract info — surfaces vendor + tracking when subcontracted.
     * Includes Phase 5-E's new expected_return_at and turnover_method.
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
            'agreed_price_per_sample' => $assignment->agreed_price_per_sample
                ? (float) $assignment->agreed_price_per_sample : null,
            'waybill_number'        => $assignment->waybill_number,
            'gc_chat_link'          => $assignment->gc_chat_link,
            'vendor_contact_number' => $assignment->vendor_contact_number,
            'notes'                 => $assignment->notes,
            'vendor' => $vendor ? [
                'id'             => $vendor->id,
                'name'           => $vendor->name ?? null,
                'address'        => $vendor->address ?? null,
                'contact_number' => $vendor->contact_number ?? null,
            ] : null,
        ];
    }

    /**
     * items_json defensive helper.
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
