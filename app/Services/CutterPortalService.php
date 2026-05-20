<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Models\StageFabricLog;
use App\Models\StageSampleUpload;
use App\Models\StageSubcontractAssignment;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-B — Cutter Portal data aggregator.
 *
 * Builds the full portal context for one (order, stage) pair:
 *   1. Order details (PO, client, brand, garment, color, qty, deadline)
 *   2. Size chart & pattern guide
 *   3. Fabric & waste tracking (existing logs + running totals)
 *   4. Material requests for this stage (from Phase 3)
 *   5. Sample uploads (existing photos + statuses)
 *   6. Recent activity (last N audit log entries for this stage)
 *
 * Called by CutterPortalController::context() to drive the page render.
 */
class CutterPortalService
{
    /**
     * Resolve the full Cutter portal payload for a stage.
     *
     * @throws ValidationException if stage doesn't belong to a cutting context
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
                'order_stage_id' => "Stage '{$stage->stage}' is not a cutter portal stage.",
            ]);
        }

        $order = Order::find($stage->order_id);
        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => 'Order not found.',
            ]);
        }

        // Phase = 'sample' or 'mass' based on stage slug.
        $phase = $stage->stage === 'sample_creation' ? 'sample' : 'mass';

        return [
            'order'           => $this->orderDetails($order),
            'stage'           => $this->stageContext($stage, $phase),
            'size_chart'      => $this->sizeChart($order),
            'fabric_tracking' => $this->fabricTracking($stage),
            'material_requests' => $this->materialRequestsForStage($stage),
            'sample_uploads'  => $this->sampleUploads($stage),
            'activity_log'    => $this->recentActivity($stage, 10),
            'subcontract'     => $this->subcontractInfo($stage),
        ];
    }

    // ── Section builders ────────────────────────────────────────────

    /**
     * Top section — order info as the portal needs it.
     */
    protected function orderDetails(Order $order): array
    {
        // items_json may be cast to array by the model OR may be a raw
        // JSON string in some contexts. Handle both defensively.
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

    /**
     * Stage-specific context (status, started_at, phase).
     */
    protected function stageContext(OrderStage $stage, string $phase): array
    {
        return [
            'id'           => $stage->id,
            'stage'        => $stage->stage,
            'sequence'     => $stage->sequence,
            'status'       => $stage->status,
            'phase'        => $phase,                  // 'sample' | 'mass'
            'service_type' => $stage->service_type ?? OrderStage::SERVICE_IN_HOUSE,
            'started_at'   => $stage->started_at?->toDateTimeString(),
            'completed_at' => $stage->completed_at?->toDateTimeString(),
            'assigned_to'  => $stage->assigned_to,
            'notes'        => $stage->notes,
        ];
    }

    /**
     * Size chart from items_json. Each item has its size + quantity.
     */
    protected function sizeChart(Order $order): array
    {
        $items = $this->itemsAsArray($order);

        return collect($items)
            ->map(fn ($i) => [
                'size'     => $i['size'] ?? null,
                'quantity' => (int) ($i['quantity'] ?? 0),
            ])
            ->filter(fn ($i) => $i['size'] !== null)
            ->values()
            ->all();
    }

    /**
     * Normalize items_json to an array regardless of whether the model
     * cast is active.
     *
     * - If the Order model casts items_json to 'array' (current case),
     *   $order->items_json is already an array → return as-is.
     * - If somehow accessed before the cast applies (raw query, mocked
     *   model, etc.), it'll be a JSON string → decode.
     * - If null/missing → return [].
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

    /**
     * All fabric logs for this stage + running totals.
     */
    protected function fabricTracking(OrderStage $stage): array
    {
        $logs = StageFabricLog::where('order_stage_id', $stage->id)
            ->with('loggedBy:id,name')
            ->orderBy('id', 'desc')
            ->get();

        $totalUsed  = (float) $logs->sum('fabric_used_kg');
        $totalWaste = (float) $logs->sum('waste_kg');

        return [
            'logs'                  => $logs->map(fn ($l) => [
                'id'                  => $l->id,
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
            'totals' => [
                'fabric_used_kg'      => round($totalUsed, 2),
                'waste_kg'            => round($totalWaste, 2),
                'usable_remaining_kg' => round($totalUsed - $totalWaste, 2),
            ],
        ];
    }

    /**
     * Material requests filed against this stage (from Phase 3).
     * Just a summary list — the full MR data is fetched via Phase 3 endpoints.
     *
     * NOTE: Phase 3 migration uses `stage_id` (not `order_stage_id`)
     * for the foreign key to order_stages. Also: priority and needed_by
     * columns don't exist in the Phase 3 schema — the mockup shows them
     * but they're not yet supported by the data layer.
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
     * Sample uploads (front + back photos) for this stage.
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
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($u->photo_front_path)
                    : null,
                'photo_back_url'    => $u->photo_back_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($u->photo_back_path)
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

    /**
     * Recent audit-log entries for this stage.
     */
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
     * Phase 5-D — Active subcontract assignment for this stage, if any.
     * Returns null when service_type is 'in_house'.
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
}
