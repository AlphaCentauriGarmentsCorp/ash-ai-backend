<?php

namespace App\Services;

use App\Models\Materials;
use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageFabricLog;
use App\Models\StageInkLog;
use App\Models\User;
use Illuminate\Validation\ValidationException;

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
 *
 * Owner decision (2026-07-28) — the sample-phase prep stage is no longer a
 * read-only "pull from stock" acknowledgment. Material Prep now PICKS
 * materials from the catalog for the sample too, going through the exact
 * same create()+approve() path as mass (a shortfall auto-spawns a PR,
 * exactly like mass). Both phases are therefore driven by ONE requirement
 * path, resolved against whichever Material Prep stage (sample or mass) is
 * currently active on the order (OrderStagesService::activeMaterialPrepStage
 * already generalises over both — see SM Rework CP1). The only remaining
 * difference is that sample has no prior usage logs to SUGGEST from (sample
 * cutting/printing/sewing happen AFTER this tier), so its suggestion list is
 * always empty — the role picks manually from the full catalog.
 */
class MaterialPrepRequirementService
{
    public function __construct(
        protected MaterialRequestService $materialRequests,
        protected OrderStagesService $orderStages,
    ) {}

    /** Sample stages whose usage logs seed the MASS requirement suggestion. */
    protected const SAMPLE_FABRIC_STAGES = ['sample_cutting', 'sample_sewing'];
    protected const SAMPLE_INK_STAGES    = ['sample_printing'];
    protected const MATERIAL_PREP_STAGE         = 'material_prep_mass';
    protected const MATERIAL_PREP_SAMPLE_STAGE  = 'material_prep_sample';

    /**
     * Full requirement state for an order at the Material Prep stage:
     * the saved requirement (MR + resulting PR) if one exists, otherwise a
     * suggestion (mass only — sample has none) the role can review.
     *
     * Resolves against whichever Material Prep stage is currently ACTIVE on
     * the order (sample or mass) rather than assuming mass, so this now
     * works correctly for both phases.
     */
    public function stateForOrder(Order $order): array
    {
        $stage = $this->orderStages->activeMaterialPrepStage($order->id);
        $phase = $this->phaseForStage($stage);
        $existing = $stage ? $this->existingRequirementForStage($stage) : null;

        return [
            'order'      => $this->orderSummary($order),
            'order_qty'  => $this->orderQty($order),
            'phase'      => $phase,                                       // 'sample' | 'mass' | null
            'existing'   => $existing,                                    // null until saved
            // Sample has no prior usage logs to suggest from — the role
            // picks manually from the full catalog for that phase.
            'suggestion' => ($existing || $phase !== 'mass') ? [] : $this->suggestForOrder($order),
            'can_save'   => $existing === null && $stage !== null,
        ];
    }

    /**
     * Orders currently sitting at EITHER Material Prep stage.
     *
     * Material Prep owns two stages: material_prep_sample (tier 6, the sample
     * sourcing fork that runs parallel to screen_making) and material_prep_mass
     * (tier 13, mass sourcing). Both are surfaced here — an order at the sample
     * stage was previously invisible in the portal, which left it locked and
     * unable to join the fork to sample_cutting.
     *
     * Both phases now go through the SAME requirement/suggestion + Auto-PR
     * flow: each row carries requirement_set / purchase_needed / pr_status.
     * (Sample used to be a read-only "pull from stock" acknowledgment with no
     * MR at all — the owner asked for a real pick-and-save flow instead, so
     * it can spawn a Purchase Request on shortfall exactly like mass does.)
     *
     * The `phase` field tells the frontend which copy/suggestion behaviour to
     * render per row (sample has no suggestion source; mass does).
     */
    public function ordersAtMaterialPrep(): array
    {
        $stages = OrderStage::query()
            ->whereIn('stage', [self::MATERIAL_PREP_SAMPLE_STAGE, self::MATERIAL_PREP_STAGE])
            ->where('status', 'in_progress')
            ->with('order:id,po_code,client_brand,client_name')
            ->orderBy('sequence')
            ->get();

        return $stages
            ->filter(fn ($s) => $s->order !== null)
            ->map(function ($s) {
                $existing = $this->existingRequirementForStage($s);
                return [
                    'order'           => $this->orderSummary($s->order),
                    'phase'           => $this->phaseForStage($s),
                    'stage'           => $s->stage,
                    'requirement_set' => $existing !== null,
                    'purchase_needed' => $existing['purchase_needed'] ?? null,
                    'pr_status'       => $existing['pr']['status'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /** Sample-log-based suggested requirement rows (mass phase only). */
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
     * Save the confirmed requirement → create the MR against whichever
     * Material Prep stage is currently active (sample or mass — the explicit
     * stage_id matters here because the sample stage runs parallel to
     * screen_making, so the order's "current stage" alone is ambiguous),
     * then approve it so the existing Auto-PR / stock-decrement path runs
     * immediately.
     *
     * @param  array<int,array{material_id:int,quantity_requested:numeric,notes?:string}>  $items
     */
    public function saveForOrder(Order $order, array $items, User $actor): array
    {
        $stage = $this->orderStages->activeMaterialPrepStage($order->id);
        if (! $stage) {
            throw ValidationException::withMessages([
                'order' => 'No active Material Prep stage for this order.',
            ]);
        }

        $phase = $this->phaseForStage($stage);

        $mr = $this->materialRequests->create([
            'order_id' => $order->id,
            'stage_id' => $stage->id,
            'items'    => $items,
            'reason'   => $phase === 'sample'
                ? 'Material Prep requirement (sample).'
                : 'Material Prep requirement (mass production).',
        ], $actor);

        // "Auto on save": shortfalls → Purchase Request; else decrement stock.
        $mr = $this->materialRequests->approve($mr, $actor);

        return $this->requirementPayload($mr);
    }

    /**
     * Owner decision (2026-07-28) — the materials Material Prep confirmed for
     * an order (sample and/or mass) need to be visible downstream as
     * "Material Details" in every later portal (Cutter, Printer, Sewer,
     * QA/Packer), not just re-shown inside Material Prep's own screen.
     *
     * Returns one entry per active MR created by Material Prep for this
     * order (oldest first — sample phase before mass phase), each in the
     * same shape as the saved-requirement view plus a `phase` label.
     */
    public function materialDetailsForOrder(Order $order): array
    {
        $mrs = MaterialRequest::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [
                MaterialRequest::STATUS_PENDING,
                MaterialRequest::STATUS_APPROVED,
                MaterialRequest::STATUS_AUTO_PR,
            ])
            ->whereHas('stage', fn ($q) => $q->whereIn('stage', [
                self::MATERIAL_PREP_SAMPLE_STAGE,
                self::MATERIAL_PREP_STAGE,
            ]))
            ->with('stage:id,stage')
            ->orderBy('id')
            ->get();

        return $mrs->map(function (MaterialRequest $mr) {
            $payload = $this->requirementPayload($mr);
            $payload['phase'] = $this->phaseForStage($mr->stage);
            $payload['stage'] = $mr->stage?->stage;

            return $payload;
        })->values()->all();
    }

    /**
     * Owner decision (2026-07-28) — the Review Hub card for a Material Prep
     * stage (sample or mass) should show exactly what was picked for THIS
     * stage, mirroring the reviewSummary() pattern the other portals
     * (Graphic Artist, Screen Maker, Cutter) already use. Falls through to
     * the generic "No artifact uploaded" card when nothing has been saved
     * yet — same convention as the sibling blocks, which must not make an
     * untouched stage look worked.
     */
    public function reviewSummary(Order $order, OrderStage $stage): array
    {
        return [
            'kind'        => 'material_prep',
            'phase'       => $this->phaseForStage($stage),
            'requirement' => $this->existingRequirementForStage($stage), // null until saved
            'stage_notes' => $stage->notes,
        ];
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

    /** 'sample' | 'mass' | null (no active/known prep stage) from a stage slug. */
    protected function phaseForStage(?OrderStage $stage): ?string
    {
        if (! $stage) {
            return null;
        }

        return $stage->stage === self::MATERIAL_PREP_SAMPLE_STAGE ? 'sample' : 'mass';
    }

    /**
     * The active, non-rejected requirement attached to a specific Material
     * Prep OrderStage row (sample or mass — the stage is passed in directly
     * rather than re-derived, since a sample stage runs parallel to
     * screen_making and can't be looked up by stage-type alone).
     *
     * Only an ACTIVE requirement counts. A rejected MR must NOT be shown as
     * the saved requirement (it would display a stale snapshot with no PR
     * and block the role from saving a real one).
     */
    protected function existingRequirementForStage(OrderStage $stage): ?array
    {
        $mr = MaterialRequest::where('order_id', $stage->order_id)
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
