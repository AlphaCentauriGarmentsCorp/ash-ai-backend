<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderDesign;
use App\Models\OrderStage;
use App\Models\Pantone;
use App\Models\ScreenAssignment;
use App\Models\StageAuditLog;
use App\Models\StageSubcontractAssignment;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-F — Screen Maker Portal data aggregator.
 *
 * Screen Maker is mostly a READ-ONLY portal. Their job:
 *   1. See the order + the design output the Graphic Artist produced
 *   2. See which physical screens are mapped (from screen_assignments)
 *   3. Make the screens, then mark the stage as done
 *
 * SM Rework CP1 — the portal now mirrors the Graphic Artist portal's
 * data surface so the reworked page can render:
 *   - Order Details          (enriched: Apparel Info + Production Details
 *                             + colour-hex chips + design name)  ← same
 *                             shape as GraphicArtistPortalService
 *   - Design Details         (read-only view of the GA output — the
 *                             hydrated placements + Pantones and the
 *                             order's label specs / shared Label Design)
 *   - Notes / Instructions   (order.notes + the Hub → screen_maker
 *                             role-directed instruction thread)
 *
 * SM Rework CP2 — "Designs to Make Screen" (a read-only recap of already-
 * saved assignments) is replaced by `screen_slots`: one row per
 * (placement, colour) the placement actually needs, each carrying its
 * current screen_assignments row if one exists yet. `available_screens`
 * is the picker's source list. The write path
 * (ScreenAssignmentService::assign/unassign, gated by
 * access.screen-making) writes to the SAME screen_assignments table the
 * Printer Portal and CSR Review Hub already read from, so this is the
 * first thing that actually populates it from the frontend.
 *
 * The Screen Maker does NOT edit the GA output — the placements / labels
 * here are read-only. Notes + mark-as-done still route through the
 * existing OrderStagesController endpoints (no portal-specific writes).
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

        $design = OrderDesign::where('order_id', $order->id)
            ->with('placements')
            ->first();

        return [
            'order'             => $this->orderDetails($order),
            'stage'             => $this->stageContext($stage),
            // Read-only GA output — the reworked "Design Details" section.
            'placements'        => $this->placements($design),
            'pantones_used'     => $this->pantonesUsed($design),
            // SM Rework CP2 — Screens Used input. One slot per (placement,
            // colour) with its current screen_assignments row if any, plus
            // the pickable inventory list for the dropdown.
            'screen_slots'      => $this->screenSlots($order, $design),
            'available_screens' => $this->availableScreens(),
            'material_requests' => $this->materialRequestsForStage($stage),
            'activity_log'      => $this->recentActivity($stage, 10),
            'subcontract'       => $this->subcontractInfo($stage),
            // Hub → Screen Maker instruction thread (ORDER-level, role-directed).
            'role_notes'        => app(OrderRoleNoteService::class)->forRole($order->id, 'screen_maker'),
        ];
    }

    /**
     * SM Rework CP1 — Review Hub summary of the Screen Maker stage.
     *
     * Read-only composition of the same section builders the portal
     * context uses, so the CSR Review Hub's Screen Making card shows the
     * design output the screen maker worked from (placements + Pantones),
     * the order's label specs, the physical screens mapped, and — most
     * importantly for the requirement — the maker's own "Save Notes"
     * blob (stage.notes).
     *
     * Shape intentionally parallels GraphicArtistPortalService::reviewSummary
     * so the hub frontend can consume both the same way. No soft
     * completion warnings here: the Screen Maker "Tapos na" is not a
     * warn-gated step (unlike the Graphic Artist stage).
     *
     * @return array<string,mixed>
     */
    public function reviewSummary(Order $order): array
    {
        $design = OrderDesign::where('order_id', $order->id)
            ->with('placements')
            ->first();

        $smStage = OrderStage::where('order_id', $order->id)
            ->where('stage', 'screen_making')
            ->first(['id', 'notes']);

        return [
            'kind'          => 'screen_making',
            'design'        => $design ? $this->designContext($design) : null,
            'placements'    => $this->placements($design),
            'pantones_used' => $this->pantonesUsed($design),
            'labels'        => [
                'brand_label'      => is_array($order->brand_label_json) ? $order->brand_label_json : null,
                'care_label'       => is_array($order->care_label_json) ? $order->care_label_json : null,
                'label_design_url' => $order->label_design_path
                    ? $this->publicUrl($order->label_design_path)
                    : null,
            ],
            'screens'       => $this->screenSummary($order),
            'stage_notes'   => $smStage?->notes,
        ];
    }

    // ── Section builders ────────────────────────────────────────────

    /**
     * SM Rework CP1 — enriched to match the order page's Product Details
     * (Apparel Information + Production Details + Labels), identical shape
     * to GraphicArtistPortalService::orderDetails so the reworked portal
     * can reuse the GA Order Details layout. Colour fields carry a
     * best-effort resolved hex (fabric_swatches name match, falling back
     * to pantones) so the portal can render a visual chip beside the
     * colour name; unmatched names get null and the chip is omitted.
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

    protected function designContext(OrderDesign $design): array
    {
        return [
            'id'         => $design->id,
            'artist_id'  => $design->artist_id,
            'notes'      => $design->notes,
            'size_label' => $design->size_label,
        ];
    }

    /**
     * SM Rework CP1 — hydrated placements (read-only view of the GA
     * "Print Locations & Pantones" output). Ported verbatim from
     * GraphicArtistPortalService::placements so the Design Details
     * section renders identically to what the artist saved.
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
     * Aggregated unique Pantones across all placements — for an optional
     * palette strip in the Design Details section. Ported from
     * GraphicArtistPortalService::pantonesUsed.
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
     * SM Rework CP2 — "Screens Used" input rows. One row per (placement,
     * colour) the placement actually needs — expanded from color_count,
     * defaulting to 1 colour when unset — each carrying its current
     * screen_assignments row (if the screen maker already picked one)
     * plus the matching Pantone from that placement's saved colour list
     * so the picker can show "colour 2 = PANTONE 186 C" next to the
     * dropdown. Writes go through ScreenAssignmentService, NOT here —
     * this method is read-only.
     */
    protected function screenSlots(Order $order, ?OrderDesign $design): array
    {
        if (! $design || $design->placements->isEmpty()) {
            return [];
        }

        $assignments = ScreenAssignment::with('screen')
            ->where('order_id', $order->id)
            ->get()
            ->groupBy(fn ($a) => $a->placement_id . ':' . $a->color_index);

        $rows = [];
        foreach ($design->placements as $p) {
            $pantones = is_array($p->pantones) ? $p->pantones : [];
            $colorCount = max(1, (int) ($p->color_count ?? 1));

            for ($colorIndex = 1; $colorIndex <= $colorCount; $colorIndex++) {
                $assignment = $assignments->get("{$p->id}:{$colorIndex}", collect())->first();
                $pantone = $pantones[$colorIndex - 1] ?? null;

                $rows[] = [
                    'placement_id'   => $p->id,
                    'placement_type' => $p->type,
                    'mockup_image'   => $p->mockup_image,
                    'mockup_url'     => $p->mockup_image
                        ? Storage::disk('public')->url($p->mockup_image)
                        : null,
                    'color_index'    => $colorIndex,
                    'pantone'        => is_array($pantone) ? $pantone : null,
                    'assignment_id'  => $assignment?->id,
                    'screen'         => $assignment?->screen ? [
                        'id'         => $assignment->screen->id,
                        'name'       => $assignment->screen->name,
                        'size'       => $assignment->screen->size,
                        'mesh_count' => $assignment->screen->mesh_count,
                        'address'    => $assignment->screen->address,
                        'status'     => $assignment->screen->status,
                    ] : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Pickable screen inventory for the "Screens Used" dropdown — screens
     * that are free to claim (status 'available', or null for screens
     * created before the status lifecycle existed). Screens that are
     * 'in_use', 'for_reclaim', or 'damaged' are intentionally excluded
     * here; ScreenAssignmentService still allows picking an 'in_use'
     * screen belonging to another order (warn, don't block) for cases
     * where the screen maker types/selects it directly, but the
     * dropdown itself only surfaces the clean choices.
     */
    protected function availableScreens(): array
    {
        if (! Schema::hasTable('screens')) {
            return [];
        }

        return \App\Models\Screens::where(function ($q) {
            $q->whereNull('status')->orWhere('status', 'available');
        })
            ->orderBy('name')
            ->get(['id', 'name', 'size', 'mesh_count', 'address', 'status'])
            ->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'size'       => $s->size,
                'mesh_count' => $s->mesh_count,
                'address'    => $s->address,
                'status'     => $s->status,
            ])
            ->all();
    }

    /**
     * Compact per-placement physical-screen summary for the Review Hub
     * card (flat list grouped by placement). Read-only.
     */
    protected function screenSummary(Order $order): array
    {
        $assignments = ScreenAssignment::with(['screen'])
            ->where('order_id', $order->id)
            ->orderBy('placement_id')
            ->orderBy('color_index')
            ->get();

        return $assignments->map(fn ($a) => [
            'id'           => $a->id,
            'placement_id' => $a->placement_id,
            'color_index'  => (int) $a->color_index,
            'screen'       => $a->screen ? [
                'id'         => $a->screen->id,
                'name'       => $a->screen->name,
                'size'       => $a->screen->size,
                'mesh_count' => $a->screen->mesh_count,
                'address'    => $a->screen->address,
            ] : null,
        ])->all();
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
