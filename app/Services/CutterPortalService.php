<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderDesign;
use App\Models\OrderStage;
use App\Models\Pantone;
use App\Models\StageAuditLog;
use App\Models\StageFabricLog;
use App\Models\StageSampleUpload;
use App\Models\StageSubcontractAssignment;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
 * Cutter Rework CP1 — the portal now mirrors the Graphic Artist / Screen
 * Maker portals' data surface so the reworked page can render:
 *   - Order Details          (enriched: Apparel Info + Production Details
 *                             + colour-hex chips + design name)  ← same
 *                             shape as GraphicArtistPortalService /
 *                             ScreenMakerPortalService
 *   - Design Details         (read-only view of the GA output — the
 *                             hydrated placements + Pantones and the
 *                             order's label specs / shared Label Design)
 *   - Notes / Instructions   (order.notes + the Hub → cutter
 *                             role-directed instruction thread)
 *
 * The Cutter does NOT edit the GA output — the placements here are
 * read-only reference. Notes + mark-as-done still route through the
 * existing OrderStagesController endpoints (no portal-specific writes).
 *
 * Eligible stages: 'sample_cutting' and 'mass_cutting' — the cutter is
 * the first portal role that owns TWO stages on one order, so anything
 * per-stage (fabric logs, notes, the Review Hub summary) is keyed by the
 * concrete OrderStage, never by the role.
 *
 * Called by CutterPortalController::context() to drive the page render,
 * and by StageReviewController for the Review Hub's cutting cards.
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

        $eligibleStages = ['sample_cutting', 'mass_cutting'];
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
        $phase = $stage->stage === 'sample_cutting' ? 'sample' : 'mass';

        // Cutter Rework CP1 — the GA design output, read-only. Same
        // hydration as the Screen Maker portal's Design Details section.
        $design = OrderDesign::where('order_id', $order->id)
            ->with('placements')
            ->first();

        return [
            'order'           => $this->orderDetails($order),
            'stage'           => $this->stageContext($stage, $phase),
            'size_chart'      => $this->sizeChart($order),
            'fabric_tracking' => $this->fabricTracking($stage),
            'material_requests' => $this->materialRequestsForStage($stage),
            'sample_uploads'  => $this->sampleUploads($stage),
            'activity_log'    => $this->recentActivity($stage, 10),
            'subcontract'     => $this->subcontractInfo($stage),
            // Cutter Rework CP1 — read-only GA output for the portal's
            // Design Details section (full SM parity by owner decision).
            'placements'      => $this->placements($design),
            'pantones_used'   => $this->pantonesUsed($design),
            // Hub → Cutter instruction thread (ORDER-level, role-directed).
            'role_notes'      => app(OrderRoleNoteService::class)->forRole($order->id, 'cutter'),
        ];
    }

    /**
     * Cutter Rework CP1 — Review Hub summary of ONE cutting stage.
     *
     * Read-only composition for the CSR Review Hub's Sample Cutting /
     * Mass Cutting cards: the fabric usage entries the cutter logged
     * (incl. the fabric roll / batch refs — per owner decision) and the
     * cutter's own "Save Notes" blob (stage.notes).
     *
     * Shape intentionally parallels GraphicArtistPortalService /
     * ScreenMakerPortalService::reviewSummary so the hub frontend can
     * consume all three the same way — BUT it takes the concrete stage,
     * because the cutter owns two stages per order (sample + mass) and
     * each card must show only its own logs and notes.
     *
     * The stage_fabric_logs read is guarded by Schema::hasTable() — in
     * production the table exists (real migration); the guard only
     * matters for hand-built SQLite test schemas that don't include it,
     * so this service can join the shared stage-reviews code path
     * WITHOUT forcing every existing endpoint test to add the table
     * (same convention as StageWasteSummaryService).
     *
     * @return array<string,mixed>
     */
    public function reviewSummary(Order $order, OrderStage $stage): array
    {
        $tracking = Schema::hasTable('stage_fabric_logs')
            ? $this->fabricTracking($stage)
            : [
                'logs'   => [],
                'totals' => [
                    'fabric_used_kg'      => 0.0,
                    'waste_kg'            => 0.0,
                    'usable_remaining_kg' => 0.0,
                ],
            ];

        return [
            'kind'          => 'cutting',
            'phase'         => $stage->stage === 'sample_cutting' ? 'sample' : 'mass',
            'fabric_logs'   => $tracking['logs'],
            'fabric_totals' => $tracking['totals'],
            'stage_notes'   => $stage->notes,
        ];
    }

    // ── Section builders ────────────────────────────────────────────

    /**
     * Top section — order info as the portal needs it.
     *
     * Cutter Rework CP1 — enriched to match the order page's Product
     * Details (Apparel Information + Production Details + Labels),
     * identical shape to GraphicArtistPortalService::orderDetails /
     * ScreenMakerPortalService::orderDetails so the reworked portal can
     * reuse the GA Order Details layout. Colour fields carry a
     * best-effort resolved hex (fabric_swatches name match, falling back
     * to pantones) so the portal can render a visual chip beside the
     * colour name; unmatched names get null and the chip is omitted.
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
            'id'              => $order->id,
            'po_code'         => $order->po_code,
            'client_name'     => $order->client_name,
            'client_brand'    => $order->client_brand,
            'shirt_color'     => $order->shirt_color,
            'shirt_color_hex' => $this->colorHex($order->shirt_color),
            'special_print'   => $order->special_print,
            'print_area'      => $order->print_area,
            'total_pcs'       => $totalPcs,
            'workflow_status' => $order->workflow_status,
            'notes'           => $order->notes,

            // ── Apparel Information (Product Details mirror) ────────
            'apparel_type'     => $order->apparelType?->name,
            'pattern_type'     => $order->patternType?->name,
            'apparel_neckline' => $order->apparelNeckline?->name,
            'print_method'     => $order->printMethod?->name,

            // ── Production Details ──────────────────────────────────
            'design_name'       => $order->design_name,
            'service_type'      => $order->service_type,
            'print_service'     => $order->print_service,
            'fabric_type'       => $order->fabric_type,
            'fabric_supplier'   => $order->fabric_supplier,
            'fabric_color'      => $order->fabric_color,
            'fabric_color_hex'  => $this->colorHex($order->fabric_color),
            'thread_color'      => $order->thread_color,
            'thread_color_hex'  => $this->colorHex($order->thread_color),
            'ribbing_color'     => $order->ribbing_color,
            'ribbing_color_hex' => $this->colorHex($order->ribbing_color),

            // ── Labels (structured specs shared with the quotation) ─
            'brand_label'      => is_array($order->brand_label_json) ? $order->brand_label_json : null,
            'care_label'       => is_array($order->care_label_json) ? $order->care_label_json : null,
            'label_design_url' => $order->label_design_path
                ? $this->publicUrl($order->label_design_path)
                : null,
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
     * Cutter Rework CP1 — hydrated placements (read-only view of the GA
     * "Print Locations & Pantones" output). Ported verbatim from
     * ScreenMakerPortalService::placements (itself ported from the GA
     * service) so the Design Details section renders identically to
     * what the artist saved.
     *
     * order_design_placements.pantones is stored as a JSON array. Each
     * element may be either a Pantone ID (int) or an inline pantone
     * descriptor (array). We resolve IDs to full records.
     */
    protected function placements(?OrderDesign $design): array
    {
        if (! $design || $design->placements->isEmpty()) {
            return [];
        }

        // Collect all Pantone IDs referenced across placements for a
        // single batched lookup.
        $ids = [];
        foreach ($design->placements as $p) {
            $raw = is_array($p->pantones) ? $p->pantones : [];
            foreach ($raw as $entry) {
                if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                    $ids[] = (int) $entry;
                } elseif (is_array($entry) && isset($entry['id'])) {
                    $ids[] = (int) $entry['id'];
                }
            }
        }
        $ids = array_values(array_unique($ids));

        $pantonesById = empty($ids)
            ? collect()
            : Pantone::whereIn('id', $ids)->get()->keyBy('id');

        return $design->placements->map(function ($p) use ($pantonesById) {
            $raw = is_array($p->pantones) ? $p->pantones : [];
            $hydrated = [];
            foreach ($raw as $entry) {
                if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                    $rec = $pantonesById->get((int) $entry);
                    if ($rec) {
                        $hydrated[] = [
                            'source'       => 'official',
                            'id'           => $rec->id,
                            'name'         => $rec->name,
                            'hexcolor'     => $rec->hexcolor,
                            'pantone_code' => $rec->pantone_code,
                        ];
                    }
                } elseif (is_array($entry)) {
                    $entrySource = isset($entry['source']) && $entry['source'] !== ''
                        ? (string) $entry['source']
                        : (isset($entry['id']) ? 'official' : 'inline');
                    $hydrated[] = [
                        'source'       => $entrySource,
                        'id'           => $entry['id']           ?? null,
                        'name'         => $entry['name']         ?? null,
                        'hexcolor'     => $entry['hexcolor']     ?? null,
                        'pantone_code' => $entry['pantone_code'] ?? null,
                    ];
                }
            }

            return [
                'id'           => $p->id,
                'type'         => $p->type,
                'color_count'  => $p->color_count !== null ? (int) $p->color_count : null,
                'mockup_image' => $p->mockup_image,
                'mockup_url'   => $p->mockup_image
                    ? $this->publicUrl($p->mockup_image)
                    : null,
                'pantones'     => $hydrated,
            ];
        })->all();
    }

    /**
     * Aggregated unique Pantones across all placements — for the palette
     * strip in the Design Details section. Ported from
     * ScreenMakerPortalService::pantonesUsed.
     */
    protected function pantonesUsed(?OrderDesign $design): array
    {
        if (! $design || $design->placements->isEmpty()) {
            return [];
        }

        $ids = [];
        $inline = [];
        foreach ($design->placements as $p) {
            $raw = is_array($p->pantones) ? $p->pantones : [];
            foreach ($raw as $entry) {
                if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                    $ids[(int) $entry] = true;
                } elseif (is_array($entry)) {
                    if (isset($entry['id'])) {
                        $ids[(int) $entry['id']] = true;
                    } else {
                        $key = ($entry['pantone_code'] ?? '') . '|' . ($entry['name'] ?? '');
                        $inline[$key] = [
                            'id'           => null,
                            'name'         => $entry['name']         ?? null,
                            'hexcolor'     => $entry['hexcolor']     ?? null,
                            'pantone_code' => $entry['pantone_code'] ?? null,
                        ];
                    }
                }
            }
        }

        $byId = [];
        if (! empty($ids)) {
            $byId = Pantone::whereIn('id', array_keys($ids))->get()
                ->map(fn ($p) => [
                    'id'           => $p->id,
                    'name'         => $p->name,
                    'hexcolor'     => $p->hexcolor,
                    'pantone_code' => $p->pantone_code,
                ])->all();
        }

        return array_values(array_merge($byId, array_values($inline)));
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

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Best-effort colour-name → hex resolution for the visual chips.
     * Order: fabric_swatches.name (hex_color) → pantones.name
     * (hexcolor). Case-insensitive exact match; null when unmatched
     * (the UI omits the chip). Table guards keep the throwaway Pest
     * schemas (which don't build fabric_swatches) safe.
     */
    protected function colorHex(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $lower = mb_strtolower($name);

        if (Schema::hasTable('fabric_swatches')) {
            $hex = \App\Models\FabricSwatch::whereRaw('LOWER(name) = ?', [$lower])
                ->value('hex_color');
            if (! empty($hex)) {
                return $hex;
            }
        }

        if (Schema::hasTable('pantones')) {
            $hex = Pantone::whereRaw('LOWER(name) = ?', [$lower])->value('hexcolor');
            if (! empty($hex)) {
                return $hex;
            }
        }

        return null;
    }

    /**
     * Build a publicly-servable URL for a stored path. Accepts paths
     * that already include the /storage/ prefix or relative disk paths.
     */
    protected function publicUrl(string $path): string
    {
        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'storage/')) {
            return '/' . $relative;
        }
        return Storage::disk('public')->url($relative);
    }
}
