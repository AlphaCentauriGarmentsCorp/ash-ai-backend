<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderDesign;
use App\Models\OrderStage;
use App\Models\Pantone;
use App\Models\ScreenAssignment;
use App\Models\StageAuditLog;
use App\Models\StageInkLog;
use App\Models\StageSampleUpload;
use App\Models\StageSubcontractAssignment;
use Illuminate\Support\Facades\Schema;
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
 *
 * Printer Rework CP1 — the portal now mirrors the Graphic Artist /
 * Screen Maker / Cutter data surface so the reworked page can render the
 * same canonical section order every other production portal uses:
 *   - Order Details          (enriched: Apparel Info + Production Details
 *                             + colour-hex chips + label specs) — the
 *                             identical shape CutterPortalService and
 *                             GraphicArtistPortalService return
 *   - Design Details         (read-only GA output: hydrated placements +
 *                             the aggregated Pantone palette). This is
 *                             ADDITIVE — the printer-specific
 *                             screen_details and print_placements
 *                             sections stay exactly as they were (owner
 *                             decision: full parity, keep both)
 *   - Notes / Instructions   (order.notes + the Hub -> printer
 *                             role-directed instruction thread)
 *
 * The printer does NOT edit the GA output — placements here are
 * read-only reference. Notes + mark-as-done still route through the
 * existing OrderStagesController endpoints (no portal-specific writes).
 *
 * Eligible stages: 'sample_printing' and 'mass_printing' — like the
 * cutter, the printer owns TWO stages per order, so anything per-stage
 * (ink logs, notes, the Review Hub summary) is keyed by the concrete
 * OrderStage, never by the role.
 *
 * Called by PrinterPortalController::showContext() to drive the page
 * render, and by StageReviewController for the Review Hub's printing
 * cards.
 */
class PrinterPortalService
{
    public function __construct(
        protected MaterialPrepRequirementService $materialPrepRequirements,
    ) {}

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

        $eligibleStages = ['sample_printing', 'mass_printing'];
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

        $phase = $stage->stage === 'sample_printing' ? 'sample' : 'mass';

        // Printer Rework CP1 — the GA design output, read-only. Same
        // hydration the Screen Maker and Cutter portals already use for
        // their Design Details section.
        $design = OrderDesign::where('order_id', $order->id)
            ->with('placements')
            ->first();

        return [
            'order'            => $this->orderDetails($order),
            'stage'            => $this->stageContext($stage, $phase),
            'screen_details'   => $this->screenDetails($order),
            'print_placements' => $this->printPlacements($order),
            'ink_tracking'     => $this->inkTracking($stage),
            'material_requests'=> $this->materialRequestsForStage($stage),
            'material_details' => $this->materialPrepRequirements->materialDetailsForOrder($order),
            'sample_uploads'   => $this->sampleUploads($stage),
            'activity_log'     => $this->recentActivity($stage, 10),
            'subcontract'      => $this->subcontractInfo($stage),
            // Printer Rework CP1 — read-only GA output for the portal's
            // Design Details section (full Cutter/SM parity).
            'placements'       => $this->placements($design),
            'pantones_used'    => $this->pantonesUsed($design),
            // Hub -> Printer instruction thread (ORDER-level, role-directed).
            'role_notes'       => app(OrderRoleNoteService::class)->forRole($order->id, 'printer'),
        ];
    }

    /**
     * Printer Rework CP1 — Review Hub summary of ONE printing stage.
     *
     * Read-only composition for the CSR Review Hub's Sample Printing /
     * Mass Printing cards. PRINTER-AUTHORED output only (owner decision):
     * the per-colour ink entries the printer logged and the printer's own
     * "Save Notes" blob (stage.notes). The screens and placements are GA /
     * Screen Maker context and already render on THEIR cards, so they are
     * deliberately not repeated here — the same rule CutterPortalService
     * and ScreenMakerPortalService follow.
     *
     * Aggregate ink used / waste totals also appear in the hub's
     * auto-computed Waste block; they ride along here so the frontend can
     * caption the per-colour table without a second lookup.
     *
     * Shape intentionally parallels CutterPortalService::reviewSummary so
     * the hub frontend consumes both the same way — and like the cutter,
     * it takes the concrete stage, because the printer owns two stages per
     * order and each card must show only its own logs and notes.
     *
     * The stage_ink_logs read is guarded by Schema::hasTable() — in
     * production the table exists (real migration); the guard only matters
     * for hand-built SQLite test schemas that don't include it, so this
     * service can join the shared stage-reviews code path safely.
     */
    public function reviewSummary(Order $order, OrderStage $stage): array
    {
        $tracking = Schema::hasTable('stage_ink_logs')
            ? $this->inkTracking($stage)
            : [
                'logs'   => [],
                'totals' => [
                    'ink_used_kg'         => 0.0,
                    'ink_waste_kg'        => 0.0,
                    'usable_remaining_kg' => 0.0,
                ],
            ];

        return [
            'kind'        => 'printing',
            'phase'       => $stage->stage === 'sample_printing' ? 'sample' : 'mass',
            'ink_logs'    => $tracking['logs'],
            'ink_totals'  => $tracking['totals'],
            'stage_notes' => $stage->notes,
        ];
    }

    // ── Section builders ────────────────────────────────────────────

    /**
     * Top section — order info as the portal needs it.
     *
     * Printer Rework CP1 — enriched to match the order page's Product
     * Details (Apparel Information + Production Details + Labels),
     * byte-for-byte the same shape CutterPortalService::orderDetails and
     * GraphicArtistPortalService::orderDetails return, so the reworked
     * page can render the shared OrderDetailsSectionGA layout. Colour
     * fields carry a best-effort resolved hex (fabric_swatches name match,
     * falling back to pantones) so the portal can show a visual chip beside
     * the colour name; unmatched names get null and the chip is omitted.
     *
     * The pre-rework keys are all still present and unchanged — this is
     * purely additive, so nothing that already read this payload breaks.
     */
    protected function orderDetails(Order $order): array
    {
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

            // -- Apparel Information (Product Details mirror) --------
            'apparel_type'     => $order->apparelType?->name,
            'pattern_type'     => $order->patternType?->name,
            'apparel_neckline' => $order->apparelNeckline?->name,
            'print_method'     => $order->printMethod?->name,

            // -- Production Details ---------------------------------
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

            // -- Labels (structured specs shared with the quotation) -
            'brand_label'      => is_array($order->brand_label_json) ? $order->brand_label_json : null,
            'care_label'       => is_array($order->care_label_json) ? $order->care_label_json : null,
            'label_design_url' => $order->label_design_path
                ? $this->publicUrl($order->label_design_path)
                : null,
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

    /**
     * Printer Rework CP1 — read-only hydrated GA placements for the
     * Design Details section. Ported verbatim from
     * CutterPortalService::placements so all three portals render
     * identical data.
     *
     * order_design_placements.pantones is stored as a JSON array. Each
     * element may be either a Pantone ID (int) or an inline pantone
     * descriptor (array). We resolve IDs to full records.
     *
     * NOTE — this is NOT the same thing as printPlacements() above.
     * printPlacements() is the printer's own mockup/measurement guide and
     * is untouched; this one feeds the shared Design Details section.
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
     * CutterPortalService::pantonesUsed.
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
     * Best-effort colour name -> hex resolution, for the Order Details
     * colour chips. Checks fabric_swatches first (hex_color), then falls
     * back to the Pantone catalog (hexcolor). Case-insensitive exact
     * match; null when unmatched (the UI omits the chip). Table guards
     * keep the throwaway Pest schemas (which don't build fabric_swatches)
     * safe. Ported from CutterPortalService::colorHex.
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
     * Build a publicly-servable URL for a stored path. Accepts paths that
     * already include the /storage/ prefix or relative disk paths.
     * Ported from CutterPortalService::publicUrl.
     */
    protected function publicUrl(string $path): string
    {
        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'storage/')) {
            return '/' . $relative;
        }
        return Storage::disk('public')->url($relative);
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
