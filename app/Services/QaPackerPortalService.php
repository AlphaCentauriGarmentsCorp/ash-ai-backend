<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPackingBox;
use App\Models\OrderStage;
use App\Models\PackingChecklistItem;
use App\Models\QaChecklistItem;
use App\Models\RejectReason;
use App\Models\StageRejectLog;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7-B Bundle 1 — QA/Packer Portal data aggregator.
 *
 * Mirrors the established portal-service pattern (CutterPortalService,
 * SewerPortalService, etc.): a single buildContext($orderStageId) call
 * returns everything the QA/Packer portal page needs to render its
 * eight sections.
 *
 * This service is READ-ONLY for the order/stage state — all mutating
 * operations live in QaPackerSubmitService and dedicated logger services.
 *
 * Eligible stages: 'quality_control' and 'packing'. The portal serves
 * both because per spec the same person typically does QA inspection
 * AND packing for the same order at ACGC's scale.
 *
 * Sections returned (matches spec doc structure):
 *   1. task          — current task overview (PO, client, qty, deadline)
 *   2. reference_images — read-only gallery (mockups, samples, etc.)
 *   3. qa_checklist  — the 7-item master list (active=true only)
 *   4. packing_checklist — the 7-item master list (active=true only)
 *   5. reject_reasons — 7 dropdown values for the Add Reject / Repair forms
 *   6. rejects_repairs — already-logged items for this stage
 *   7. packing_boxes  — boxes created for this order so far
 *   8. activity_log   — last N audit-log entries for the stage
 */
class QaPackerPortalService
{
    /**
     * Stage slugs the QA/Packer portal serves.
     */
    public const ELIGIBLE_STAGES = ['quality_control', 'packing'];

    /**
     * How many recent audit entries to include in the activity feed.
     */
    public const ACTIVITY_LIMIT = 10;

    /**
     * Build the full portal payload for a single (order, stage) pair.
     *
     * @throws ValidationException if the stage doesn't exist or isn't QA/Packer-eligible.
     */
    public function buildContext(int $orderStageId): array
    {
        $stage = OrderStage::find($orderStageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage not found.',
            ]);
        }

        if (! in_array($stage->stage, self::ELIGIBLE_STAGES, true)) {
            throw ValidationException::withMessages([
                'order_stage_id' => "Stage '{$stage->stage}' is not a QA/Packer portal stage.",
            ]);
        }

        $order = Order::find($stage->order_id);
        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => 'Order not found.',
            ]);
        }

        return [
            'task'               => $this->taskOverview($order, $stage),
            'reference_images'   => $this->referenceImages($order),
            'qa_checklist'       => $this->qaChecklist(),
            'packing_checklist'  => $this->packingChecklist(),
            'reject_reasons'     => $this->rejectReasons(),
            'rejects_repairs'    => $this->rejectsAndRepairs($stage),
            'packing_boxes'      => $this->packingBoxes($order),
            'activity_log'       => $this->recentActivity($stage, self::ACTIVITY_LIMIT),
        ];
    }

    // ── Section builders ────────────────────────────────────────────

    /**
     * Top-of-page task summary. Read by TaskOverviewHeader (Bundle 2).
     *
     * Keeps fields explicit (not an Order::toArray() dump) so the QA
     * portal never accidentally exposes costing/payment/supplier fields
     * — the "What QA/Packer Should NOT See" list in 7-B.1 is binding.
     *
     * Field mapping follows the actual Order schema:
     *   - po_code (not po_number)
     *   - client_name / client_brand are denormalised strings on Order
     *   - deadline (date) is the Phase 6-A CSR-tracked field
     *   - total_pcs derived from items_json (same pattern as CutterPortal)
     */
    protected function taskOverview(Order $order, OrderStage $stage): array
    {
        return [
            'order_id'       => $order->id,
            'order_stage_id' => $stage->id,
            'po_code'        => $order->po_code ?? null,
            'client_name'    => $order->client_name ?? null,
            'client_brand'   => $order->client_brand ?? null,
            'shirt_color'    => $order->shirt_color ?? null,
            'special_print'  => $order->special_print ?? null,
            'total_pcs'      => $this->totalPiecesFromItemsJson($order),
            'deadline'       => $order->deadline,
            'stage'          => $stage->stage,
            'stage_status'   => $stage->status,
            'priority'       => $order->priority ?? 'normal',
            'rush_order'     => (bool) ($order->rush_order ?? false),
            'assigned_to'    => $stage->assigned_to,
        ];
    }

    /**
     * Defensive items_json reader.
     *
     * Matches the pattern in CutterPortalService: items_json may be
     * cast to array by the model, may arrive as a raw JSON string from
     * a query, or may be null on legacy rows.
     */
    protected function totalPiecesFromItemsJson(Order $order): int
    {
        $raw = $order->items_json;
        $items = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);

        $total = 0;
        foreach ($items as $item) {
            if (is_array($item) && isset($item['quantity'])) {
                $total += (int) $item['quantity'];
            }
        }
        return $total;
    }

    /**
     * Read-only reference image gallery.
     *
     * Pulled from:
     *   - order_design_files where kind ∈ {front_mockup, back_mockup}
     *     and is_latest=true  (approved/latest mockups)
     *   - stage_sample_uploads where sample_status='approved'
     *     (approved sample photos)
     *
     * Returns a flat list of {kind, label, url} entries that the
     * frontend renders as a gallery. Bundle 2 may add a "packing guide"
     * source if/when one becomes a first-class entity.
     *
     * Each source is guarded by Schema::hasTable so the portal degrades
     * gracefully if a table is missing (e.g., a partial-deploy or test
     * environment that doesn't migrate Phase 5-H/6 tables).
     */
    protected function referenceImages(Order $order): array
    {
        $images = [];

        if (Schema::hasTable('order_design_files')
            && class_exists(\App\Models\OrderDesignFile::class)
        ) {
            $mockups = \App\Models\OrderDesignFile::query()
                ->where('order_id', $order->id)
                ->where('is_latest', true)
                ->whereIn('kind', [
                    \App\Models\OrderDesignFile::KIND_FRONT_MOCKUP,
                    \App\Models\OrderDesignFile::KIND_BACK_MOCKUP,
                ])
                ->get(['id', 'kind', 'file_path', 'original_name']);

            foreach ($mockups as $m) {
                $images[] = [
                    'kind'  => 'mockup',
                    'label' => $m->kind === \App\Models\OrderDesignFile::KIND_FRONT_MOCKUP
                        ? 'Front Mockup'
                        : 'Back Mockup',
                    'url'   => $m->file_path,
                ];
            }
        }

        if (Schema::hasTable('stage_sample_uploads')
            && class_exists(\App\Models\StageSampleUpload::class)
        ) {
            $samples = \App\Models\StageSampleUpload::query()
                ->where('order_id', $order->id)
                ->where('sample_status', 'approved')
                ->get(['id', 'photo_front_path', 'photo_back_path']);

            foreach ($samples as $s) {
                foreach ([
                    'photo_front_path' => 'Approved Sample (Front)',
                    'photo_back_path'  => 'Approved Sample (Back)',
                ] as $col => $label) {
                    if (! empty($s->{$col})) {
                        $images[] = [
                            'kind'  => 'sample',
                            'label' => $label,
                            'url'   => $s->{$col},
                        ];
                    }
                }
            }
        }

        return $images;
    }

    /** Master 7-item QA checklist (ordered, active only). */
    protected function qaChecklist(): array
    {
        return QaChecklistItem::where('active', true)
            ->orderBy('display_order')
            ->get(['id', 'slug', 'label', 'display_order'])
            ->toArray();
    }

    /** Master 7-item Packing checklist (ordered, active only). */
    protected function packingChecklist(): array
    {
        return PackingChecklistItem::where('active', true)
            ->orderBy('display_order')
            ->get(['id', 'slug', 'label', 'display_order'])
            ->toArray();
    }

    /** Master 7 reject reasons for the Add Reject / Add Repair dropdowns. */
    protected function rejectReasons(): array
    {
        return RejectReason::where('active', true)
            ->orderBy('display_order')
            ->get(['id', 'slug', 'label', 'is_fabric', 'display_order'])
            ->toArray();
    }

    /**
     * Rejects and repairs logged for this stage so far.
     *
     * Returns a single combined list (UI splits them by disposition).
     * Includes reason label + logged-by user name for display.
     */
    protected function rejectsAndRepairs(OrderStage $stage): array
    {
        return StageRejectLog::with(['reason:id,slug,label', 'loggedBy:id,name'])
            ->where('order_stage_id', $stage->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (StageRejectLog $log) => [
                'id'             => $log->id,
                'disposition'    => $log->disposition,
                'quantity_pcs'   => $log->quantity_pcs,
                'reason'         => $log->reason ? [
                    'id'    => $log->reason->id,
                    'slug'  => $log->reason->slug,
                    'label' => $log->reason->label,
                ] : null,
                'photo_path'     => $log->photo_path,
                'notes'          => $log->notes,
                'logged_by'      => $log->loggedBy?->name,
                'created_at'     => $log->created_at?->toDateTimeString(),
            ])
            ->toArray();
    }

    /** All packing boxes for the order so far. Sorted by box_number ASC. */
    protected function packingBoxes(Order $order): array
    {
        return OrderPackingBox::where('order_id', $order->id)
            ->orderBy('box_number')
            ->get()
            ->map(fn (OrderPackingBox $b) => [
                'id'             => $b->id,
                'box_number'     => $b->box_number,
                'qr_code'        => $b->qr_code,
                'contents_json'  => $b->contents_json,
                'total_pieces'   => $b->totalPieces(),
                'weight_kg'      => $b->weight_kg !== null ? (float) $b->weight_kg : null,
                'sealed_at'      => $b->sealed_at?->toDateTimeString(),
                'is_sealed'      => $b->isSealed(),
            ])
            ->toArray();
    }

    /**
     * Recent audit entries scoped to this stage.
     *
     * StageAuditLog already exists and is used by every other portal
     * for the activity feed — same shape here. Column names follow the
     * existing schema: `from_status` / `to_status` (not old/new).
     */
    protected function recentActivity(OrderStage $stage, int $limit): array
    {
        $auditClass = '\App\Models\StageAuditLog';
        if (! class_exists($auditClass)) {
            return [];
        }

        // The audit table's user FK is `user_id`, not actor_id — so we
        // resolve the user via direct lookup rather than relying on a
        // model relation that may or may not exist depending on history.
        $rows = $auditClass::where('order_stage_id', $stage->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $userIds = $rows->pluck('user_id')->filter()->unique()->values()->all();
        $userNamesById = empty($userIds)
            ? []
            : \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id')->all();

        return $rows->map(fn ($row) => [
            'id'          => $row->id,
            'action'      => $row->action,
            'from_status' => $row->from_status ?? null,
            'to_status'   => $row->to_status ?? null,
            'notes'       => $row->notes ?? null,
            'actor'       => $userNamesById[$row->user_id] ?? null,
            'created_at'  => $row->created_at?->toDateTimeString(),
        ])->toArray();
    }
}
