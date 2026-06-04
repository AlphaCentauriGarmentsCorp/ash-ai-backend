<?php

namespace App\Services;

use App\Models\Materials;
use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageFabricLog;
use App\Models\StageInkLog;
use App\Models\User;

/**
 * Change 18 — Material Prep stage requirement surfacing.
 *
 * The Material Prep (mass) stage was empty: the role had nothing to act on.
 * This service surfaces the order's material requirement so it can be sourced.
 *
 * Source (owner decision): SUGGEST from the sample-phase usage logs —
 * fabric logged at sample cutting/sewing (StageFabricLog, by material_type)
 * and ink logged at sample printing (StageInkLog, by ink_color) — summed and
 * scaled by the order quantity. Those logs are free-text (no material_id), so
 * each suggested line is best-effort matched to a catalog Material; the role
 * confirms the mapping and quantity before saving.
 *
 * On SAVE (owner decision: "auto on save") we reuse the existing
 * MaterialRequestService create() + approve() path, which computes shortfall
 * against live stock and auto-spawns a Purchase Request for short items
 * (status auto_pr) or decrements stock when everything is in stock
 * ("no purchase needed").
 */
class MaterialPrepRequirementService
{
    public function __construct(
        protected MaterialRequestService $materialRequests,
    ) {}

    /** Sample stages whose usage logs seed the mass requirement. */
    protected const SAMPLE_FABRIC_STAGES = ['sample_cutting', 'sample_sewing'];
    protected const SAMPLE_INK_STAGES    = ['sample_printing'];
    protected const MATERIAL_PREP_STAGE  = 'material_prep_mass';

    /**
     * Full requirement state for an order at the Material Prep stage:
     * the saved requirement (MR + resulting PR) if one exists, otherwise a
     * sample-log-based suggestion the role can review.
     */
    public function stateForOrder(Order $order): array
    {
        $existing = $this->existingRequirement($order);

        return [
            'order'      => $this->orderSummary($order),
            'order_qty'  => $this->orderQty($order),
            'existing'   => $existing,                       // null until saved
            'suggestion' => $existing ? [] : $this->suggestForOrder($order),
            'can_save'   => $existing === null,              // one requirement per order (v1)
        ];
    }

    /** Orders currently sitting at the Material Prep (mass) stage. */
    public function ordersAtMaterialPrep(): array
    {
        $stages = OrderStage::query()
            ->where('stage', self::MATERIAL_PREP_STAGE)
            ->where('status', 'in_progress')
            ->with('order:id,po_code,client_brand,client_name')
            ->get();

        return $stages
            ->filter(fn ($s) => $s->order !== null)
            ->map(function ($s) {
                $existing = $this->existingRequirement($s->order);
                return [
                    'order'           => $this->orderSummary($s->order),
                    'requirement_set' => $existing !== null,
                    'purchase_needed' => $existing['purchase_needed'] ?? null,
                    'pr_status'       => $existing['pr']['status'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /** Sample-log-based suggested requirement rows. */
    public function suggestForOrder(Order $order): array
    {
        $orderQty = max(1, $this->orderQty($order));
        $rows = [];

        // Fabric usage from sample cutting/sewing, grouped by material_type.
        $fabric = StageFabricLog::query()
            ->where('stage_fabric_logs.order_id', $order->id)
            ->join('order_stages', 'order_stages.id', '=', 'stage_fabric_logs.order_stage_id')
            ->whereIn('order_stages.stage', self::SAMPLE_FABRIC_STAGES)
            ->selectRaw('stage_fabric_logs.material_type as label, SUM(stage_fabric_logs.fabric_used_kg) as used')
            ->groupBy('stage_fabric_logs.material_type')
            ->get();

        foreach ($fabric as $f) {
            $rows[] = $this->suggestionRow($f->label, (float) $f->used, $orderQty, 'fabric');
        }

        // Ink usage from sample printing, grouped by ink_color.
        $ink = StageInkLog::query()
            ->where('stage_ink_logs.order_id', $order->id)
            ->join('order_stages', 'order_stages.id', '=', 'stage_ink_logs.order_stage_id')
            ->whereIn('order_stages.stage', self::SAMPLE_INK_STAGES)
            ->selectRaw('stage_ink_logs.ink_color as label, SUM(stage_ink_logs.ink_used_kg) as used')
            ->groupBy('stage_ink_logs.ink_color')
            ->get();

        foreach ($ink as $i) {
            $rows[] = $this->suggestionRow($i->label, (float) $i->used, $orderQty, 'ink');
        }

        return $rows;
    }

    /**
     * Save the confirmed requirement → create the MR, then approve it so the
     * existing Auto-PR / stock-decrement path runs immediately.
     *
     * @param  array<int,array{material_id:int,quantity_requested:numeric,notes?:string}>  $items
     */
    public function saveForOrder(Order $order, array $items, User $actor): array
    {
        $mr = $this->materialRequests->create([
            'order_id' => $order->id,
            'items'    => $items,
            'reason'   => 'Material Prep requirement (mass production).',
        ], $actor);

        // "Auto on save": shortfalls → Purchase Request; else decrement stock.
        $mr = $this->materialRequests->approve($mr, $actor);

        return $this->requirementPayload($mr);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    protected function suggestionRow(?string $label, float $sampleUsed, int $orderQty, string $kind): array
    {
        $label = $label ?: ucfirst($kind);
        // Sample logs reflect the sample run (assumed 1 pc); scale to order qty.
        $suggestedQty = round($sampleUsed * $orderQty, 2);
        $match = $this->matchMaterial($label);

        return [
            'label'         => $label,
            'kind'          => $kind,                       // fabric | ink
            'sample_used'   => round($sampleUsed, 3),
            'order_qty'     => $orderQty,
            'suggested_qty' => $suggestedQty,
            'material_id'   => $match?->id,                 // null → role must pick
            'material_name' => $match?->name,
            'unit'          => $match?->unit,
            'stock_on_hand' => $match ? (float) $match->stock_on_hand : null,
        ];
    }

    /** Best-effort match of a free-text label to a catalog Material by name. */
    protected function matchMaterial(string $label): ?Materials
    {
        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }

        return Materials::query()
            ->where(function ($q) use ($label) {
                $q->where('name', $label)
                    ->orWhere('name', 'like', '%' . $label . '%')
                    ->orWhere('material_type', 'like', '%' . $label . '%');
            })
            ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$label])
            ->first();
    }

    protected function existingRequirement(Order $order): ?array
    {
        $stage = OrderStage::where('order_id', $order->id)
            ->where('stage', self::MATERIAL_PREP_STAGE)
            ->first();
        if (! $stage) {
            return null;
        }

        // Only an ACTIVE requirement counts. A rejected MR must NOT be shown
        // as the saved requirement (it would display a stale snapshot with no
        // PR and block the role from saving a real one).
        $mr = MaterialRequest::where('order_id', $order->id)
            ->where('stage_id', $stage->id)
            ->whereIn('status', [
                MaterialRequest::STATUS_PENDING,
                MaterialRequest::STATUS_APPROVED,
                MaterialRequest::STATUS_AUTO_PR,
            ])
            ->latest('id')
            ->first();

        return $mr ? $this->requirementPayload($mr) : null;
    }

    protected function requirementPayload(MaterialRequest $mr): array
    {
        $mr->loadMissing([
            'items.material',
            'purchaseRequest.items.material',
            'purchaseRequest.supplier',
        ]);
        $pr = $mr->purchaseRequest;

        return [
            'mr' => [
                'id'      => $mr->id,
                'mr_code' => $mr->mr_code,
                'status'  => $mr->status,
                'items'   => $mr->items->map(fn ($it) => [
                    'material_id'        => $it->material_id,
                    'material_name'      => $it->material?->name,
                    'unit'               => $it->unit,
                    'quantity_requested' => (float) $it->quantity_requested,
                    'quantity_available' => (float) $it->quantity_available,
                    'quantity_short'     => (float) $it->quantity_short,
                ])->all(),
            ],
            'purchase_needed' => $pr !== null,
            'pr' => $pr ? [
                'id'       => $pr->id,
                'pr_code'  => $pr->pr_code,
                'status'   => $pr->status,
                'supplier' => $pr->supplier?->name,
                'total'    => (float) $pr->total_amount,
            ] : null,
        ];
    }

    protected function orderQty(Order $order): int
    {
        return (int) round((float) $order->items()->sum('quantity'));
    }

    protected function orderSummary(Order $order): array
    {
        return [
            'id'           => $order->id,
            'po_code'      => $order->po_code,
            'client_brand' => $order->client_brand,
            'client_name'  => $order->client_name,
        ];
    }
}