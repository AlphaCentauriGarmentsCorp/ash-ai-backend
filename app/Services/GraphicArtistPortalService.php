<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\Order;
use App\Models\OrderDesign;
use App\Models\OrderDesignFile;
use App\Models\OrderLabelAsset;
use App\Models\OrderStage;
use App\Models\Pantone;
use App\Models\PlacementMeasurement;
use App\Models\PrintLabelPlacement;
use App\Models\ScreenAssignment;
use App\Models\StageAuditLog;
use App\Models\StageSampleUpload;
use App\Models\StageUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-H — Graphic Artist Portal data aggregator.
 *
 * The Graphic Artist's job is to produce print-ready design files and
 * label assets for the order. Sections (per Graphic_Artwork.png mockup):
 *
 *   1. Job/Order Information   (reuse OrderDetailsSection)
 *   2. Design Files            (versioned uploads: front/back design,
 *                               mockups, color separation, other)
 *   3. Print Locations & Size  (placements with measurements/Pantones)
 *   4. Pantone Colors          (aggregated across placements)
 *   5. Screen Details          (read-only — sourced from screen_assignments)
 *   6. Labels & Tags           (main label / size label / hangtag)
 *   7. Notes / Instructions    (design.notes)
 *   8. Sample Uploads          (front/back finished-sample photos)
 *   9. Material Requests       (reuse Phase 3 integration)
 *  10. Activity Log            (reuse)
 *
 * Eligible stage: 'graphic_artwork' only. Not flippable to subcontract.
 */
class GraphicArtistPortalService
{
    public function buildContext(int $orderStageId): array
    {
        $stage = OrderStage::find($orderStageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage not found.',
            ]);
        }
        if ($stage->stage !== 'graphic_artwork') {
            throw ValidationException::withMessages([
                'order_stage_id' => "Stage '{$stage->stage}' is not a graphic artist portal stage.",
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
            'order'               => $this->orderDetails($order),
            'stage'               => $this->stageContext($stage),
            'design'              => $design ? $this->designContext($design) : null,
            'design_files'        => $this->designFiles($order),
            'source_files'        => $this->sourceFiles($order),
            'placements'          => $this->placements($design),
            'suggested_placements' => $this->suggestedPlacements($order, $design),
            'pantones_used'       => $this->pantonesUsed($design),
            'placement_options'   => $this->placementOptions(),
            'pantone_options'     => $this->pantoneOptions(),
            'custom_color_options' => app(\App\Services\CustomColorService::class)->options(),
            'measurement_options' => $this->measurementOptions(),
            'label_assets'        => $this->labelAssets($order),
            'screen_details'      => $this->screenDetails($order),
            'sample_uploads'      => $this->sampleUploads($stage),
            'material_requests'   => $this->materialRequestsForStage($stage),
            'activity_log'        => $this->recentActivity($stage, 15),
            'completion_warnings' => $this->completionWarnings($order, $design),
            // Hub → GA instruction thread (ORDER-level, role-directed).
            'role_notes'          => app(OrderRoleNoteService::class)->forRole($order->id, 'graphic_artist'),
        ];
    }

    /**
     * GA Portal CP3 — Review Hub summary of the artist's saved output.
     *
     * Read-only composition of the same section builders the portal
     * context uses, so the CSR Review Hub card shows EXACTLY what the
     * artist saved — placements with artwork + Pantones, the aggregated
     * Pantone palette, label assets, design notes, and the soft
     * completion warnings (useful review context: what's still missing).
     *
     * Design FILES are intentionally NOT repeated here — they already
     * reach the hub as flat attachments via StageArtifactService
     * (latest version per kind), as do sample photos.
     *
     * @return array<string,mixed>
     */
    public function reviewSummary(Order $order): array
    {
        $design = OrderDesign::where('order_id', $order->id)
            ->with('placements')
            ->first();

        // CP7 — the aligned labels block (order-level Brand / Care-Size
        // specs + the shared Label Design) replaces the legacy per-kind
        // label_assets map, and the GA stage's freeform notes ride along
        // so the reviewer sees the artist's remarks without leaving the
        // hub.
        $gaStage = OrderStage::where('order_id', $order->id)
            ->where('stage', 'graphic_artwork')
            ->first(['id', 'notes']);

        return [
            'kind'                => 'graphic_artwork',
            'design'              => $design ? $this->designContext($design) : null,
            'placements'          => $this->placements($design),
            'pantones_used'       => $this->pantonesUsed($design),
            'labels'              => [
                'brand_label'      => is_array($order->brand_label_json) ? $order->brand_label_json : null,
                'care_label'       => is_array($order->care_label_json) ? $order->care_label_json : null,
                'label_design_url' => $order->label_design_path
                    ? $this->publicUrl($order->label_design_path)
                    : null,
            ],
            'stage_notes'         => $gaStage?->notes,
            'completion_warnings' => $this->completionWarnings($order, $design),
        ];
    }

    // ── Section builders ────────────────────────────────────────────

    /**
     * GA Portal CP5 — enriched with the order page's Product Details
     * (Apparel Information + Production Details + Labels; PO Items &
     * Size Breakdown deliberately excluded per owner decision). Colour
     * fields carry a best-effort resolved hex (fabric_swatches name
     * match, falling back to pantones) so the portal can render a
     * visual chip beside the colour name; unmatched names get null and
     * the chip is simply omitted.
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
     * All design files, ordered by kind then version DESC. The frontend
     * uses is_latest to decide which one to show by default.
     */
    protected function designFiles(Order $order): array
    {
        return OrderDesignFile::where('order_id', $order->id)
            ->orderBy('kind')
            ->orderBy('version', 'desc')
            ->get()
            ->map(fn ($f) => [
                'id'             => $f->id,
                'order_id'       => $f->order_id,
                'kind'           => $f->kind,
                'version'        => (int) $f->version,
                'is_latest'      => (bool) $f->is_latest,
                'file_path'      => $f->file_path,
                'file_url'       => $f->file_path
                    ? $this->publicUrl($f->file_path)
                    : null,
                'original_name'  => $f->original_name,
                'mime_type'      => $f->mime_type,
                'size_bytes'     => (int) $f->size_bytes,
                'uploaded_by'    => $f->uploaded_by_user_id,
                'notes'          => $f->notes,
                'created_at'     => $f->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * Placements with hydrated Pantone records.
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
                    // Already-inline descriptor (custom snapshot or legacy) —
                    // preserve shape, tag the source for the picker.
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
     * Aggregated unique Pantones across all placements — for the
     * "Pantone Colors" swatch panel at the top of the portal.
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

    protected function placementOptions(): array
    {
        return PrintLabelPlacement::orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn ($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'description' => $r->description,
            ])
            ->all();
    }

    /**
     * GA Portal CP5 — the full Pantone catalog for the portal's colour
     * picker (replaces free-typed codes). Exposed here so the artist
     * doesn't need the admin access.pantone permission.
     */
    protected function pantoneOptions(): array
    {
        return Pantone::orderBy('pantone_code')
            ->get(['id', 'name', 'hexcolor', 'pantone_code'])
            ->map(fn ($p) => [
                'id'           => $p->id,
                'name'         => $p->name,
                'hexcolor'     => $p->hexcolor,
                'pantone_code' => $p->pantone_code,
            ])
            ->all();
    }

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

        if (\Illuminate\Support\Facades\Schema::hasTable('fabric_swatches')) {
            $hex = \App\Models\FabricSwatch::whereRaw('LOWER(name) = ?', [$lower])
                ->value('hex_color');
            if (! empty($hex)) {
                return $hex;
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('pantones')) {
            $hex = Pantone::whereRaw('LOWER(name) = ?', [$lower])->value('hexcolor');
            if (! empty($hex)) {
                return $hex;
            }
        }

        return null;
    }

    protected function measurementOptions(): array
    {
        return PlacementMeasurement::orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn ($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'description' => $r->description,
            ])
            ->all();
    }

    /**
     * Label assets returned as a keyed map so the frontend can render
     * each kind's card without filtering.
     */
    protected function labelAssets(Order $order): array
    {
        $rows = OrderLabelAsset::where('order_id', $order->id)->get()->keyBy('kind');

        $present = fn ($asset) => [
            'id'                => $asset->id,
            'order_id'          => $asset->order_id,
            'kind'              => $asset->kind,
            'file_path'         => $asset->file_path,
            'file_url'          => $asset->file_path
                ? $this->publicUrl($asset->file_path)
                : null,
            'original_name'     => $asset->original_name,
            'mime_type'         => $asset->mime_type,
            'size_bytes'        => $asset->size_bytes ? (int) $asset->size_bytes : null,
            'width_in'          => $asset->width_in !== null ? (float) $asset->width_in : null,
            'height_in'         => $asset->height_in !== null ? (float) $asset->height_in : null,
            'printing_process'  => $asset->printing_process,
            'color_count'       => $asset->color_count !== null ? (int) $asset->color_count : null,
            'background_color'  => $asset->background_color,
            'material'          => $asset->material,
            'notes'             => $asset->notes,
            'uploaded_by'       => $asset->uploaded_by_user_id,
            'created_at'        => $asset->created_at?->toDateTimeString(),
            'updated_at'        => $asset->updated_at?->toDateTimeString(),
        ];

        return [
            OrderLabelAsset::KIND_MAIN_LABEL => $rows->has(OrderLabelAsset::KIND_MAIN_LABEL)
                ? $present($rows->get(OrderLabelAsset::KIND_MAIN_LABEL))
                : null,
            OrderLabelAsset::KIND_SIZE_LABEL => $rows->has(OrderLabelAsset::KIND_SIZE_LABEL)
                ? $present($rows->get(OrderLabelAsset::KIND_SIZE_LABEL))
                : null,
            OrderLabelAsset::KIND_HANGTAG    => $rows->has(OrderLabelAsset::KIND_HANGTAG)
                ? $present($rows->get(OrderLabelAsset::KIND_HANGTAG))
                : null,
        ];
    }

    /**
     * Screen details (read-only). Joins screen_assignments → screens
     * for this order, returning a flat list grouped per placement.
     */
    protected function screenDetails(Order $order): array
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
     * Finished-sample uploads for this graphic_artwork stage. Same
     * shape as Cutter/Printer/Sewer.
     */
    protected function sampleUploads(OrderStage $stage): array
    {
        return StageSampleUpload::where('order_stage_id', $stage->id)
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn ($u) => [
                'id'                => $u->id,
                'order_id'          => $u->order_id,
                'order_stage_id'    => $u->order_stage_id,
                'photo_front_path'  => $u->photo_front_path,
                'photo_back_path'   => $u->photo_back_path,
                'photo_front_url'   => $u->photo_front_path
                    ? $this->publicUrl($u->photo_front_path)
                    : null,
                'photo_back_url'    => $u->photo_back_path
                    ? $this->publicUrl($u->photo_back_path)
                    : null,
                'remarks'           => $u->remarks,
                'sample_status'     => $u->sample_status,
                'completed_at'      => $u->completed_at?->toDateTimeString(),
                'uploaded_by'       => $u->uploaded_by_user_id,
                'created_at'        => $u->created_at?->toDateTimeString(),
            ])
            ->all();
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
     * GA Portal CP1 — quotation-seeded placement suggestions.
     *
     * When the artist has not yet saved any placement for this order,
     * suggest one row per print part carried on the order's
     * print_parts_json (falling back to the source quotation's parts).
     * Each suggestion pre-fills the location name, the artwork
     * reference, and the Color# slot count from the quotation — the
     * artist confirms/edits and saves, which turns it into a real
     * order_design_placements row. Once ANY placement exists, this
     * returns [] (suggestions are a first-load aid, not a sync).
     *
     * @return array<int,array>
     */
    protected function suggestedPlacements(Order $order, ?OrderDesign $design): array
    {
        // Per-type suppression, NOT all-or-nothing.
        //
        // A quotation-seeded suggestion drops out only once a placement of
        // that SAME type has been saved. Previously ANY saved placement
        // wiped EVERY remaining suggestion, so on a multi-print order
        // (e.g. Front + Back) saving one location made the other's
        // suggestion vanish before the artist ever got to save it.
        //
        // Compared case-insensitively to mirror the (design, type)
        // uniqueness rule enforced in OrderDesignPlacementService::upsert().
        $savedTypes = [];
        if ($design) {
            foreach ($design->placements as $sp) {
                $t = mb_strtolower(trim((string) $sp->type));
                if ($t !== '') {
                    $savedTypes[$t] = true;
                }
            }
        }

        $parts = $this->asArray($order->print_parts_json);
        if (empty($parts) && $order->quotation) {
            $parts = $this->asArray($order->quotation->print_parts_json);
        }

        $suggestions = [];
        foreach ($parts as $p) {
            if (! is_array($p)) {
                continue;
            }
            $type = trim((string) ($p['part'] ?? $p['name'] ?? ''));
            if ($type === '') {
                continue;
            }

            // Skip a suggestion whose type is already saved as a placement —
            // it now lives as an editable card, not a suggestion.
            if (isset($savedTypes[mb_strtolower($type)])) {
                continue;
            }

            $isLinkPart = ($p['image_input_type'] ?? null) === 'link';
            $raw = $isLinkPart
                ? ($p['image_link'] ?? null)
                : ($p['image'] ?? $p['image_path'] ?? $p['image_link'] ?? null);
            $isLink = is_string($raw) && str_starts_with($raw, 'http');

            $colorCount = (int) ($p['color_count'] ?? 0);
            if ($colorCount <= 0) {
                $colorCount = (int) ($p['full_color_count'] ?? 0);
            }

            $suggestions[] = [
                'type'        => $type,
                'artwork_url' => $raw
                    ? ($isLink ? $raw : $this->publicUrl($raw))
                    : null,
                'is_link'     => $isLink,
                'color_count' => $colorCount > 0 ? $colorCount : null,
                'print_type'  => $p['print_type'] ?? null,
                'source'      => 'quotation',
            ];
        }

        return $suggestions;
    }

    /**
     * GA Portal CP1 — soft completeness check for the stage.
     *
     * Warn-only by decision: "Tapos na" is NEVER hard-blocked by these.
     * The frontend shows a confirm dialog listing them when the artist
     * marks the stage done. Structured rows so the UI can group/badge.
     *
     * Taglish copy — flagged for owner review.
     *
     * @return array<int,array{code:string,message:string}>
     */
    protected function completionWarnings(Order $order, ?OrderDesign $design): array
    {
        // CP5 — the no_design_files warning was removed together with the
        // portal's Design Files section (per-placement artwork replaced it).
        $warnings = [];

        $placements = $design ? $design->placements : collect();
        if ($placements->isEmpty()) {
            $warnings[] = [
                'code'    => 'no_placements',
                'message' => 'Wala pang print location na naka-set.',
            ];
            return $warnings;
        }

        foreach ($placements as $p) {
            $filled = is_array($p->pantones) ? count($p->pantones) : 0;
            $slots  = $p->color_count !== null ? (int) $p->color_count : null;

            if (empty($p->mockup_image)) {
                $warnings[] = [
                    'code'    => 'placement_no_artwork',
                    'message' => "Walang artwork ang {$p->type}.",
                ];
            }

            if ($slots !== null && $slots > 0 && $filled < $slots) {
                $warnings[] = [
                    'code'    => 'placement_pantones_incomplete',
                    'message' => "Kulang ang Pantone sa {$p->type} ({$filled}/{$slots}).",
                ];
            } elseif (($slots === null || $slots === 0) && $filled === 0) {
                $warnings[] = [
                    'code'    => 'placement_no_pantones',
                    'message' => "Walang Pantone ang {$p->type}.",
                ];
            }
        }

        return $warnings;
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Change 14 — read-only "Source / Reference Files" for the artist.
     *
     * Aggregates everything uploaded for this order EARLIER in the flow so the
     * Graphic Artist can pull the originals to work from. Three buckets:
     *   1. Quotation references — custom pattern image + label design upload.
     *   2. Per-placement artwork carried in print_parts_json (file or link).
     *   3. Generic order/stage attachments (stage_uploads).
     *
     * Deliberately separate from the artist's own versioned outputs
     * (design_files / label_assets). Read-only — view & download only.
     *
     * @return array<int,array>
     */
    protected function sourceFiles(Order $order): array
    {
        $files = [];
        $quotation = $order->quotation; // belongsTo; null for a direct order

        // 1. Quotation-level references.
        if ($quotation) {
            if (! empty($quotation->custom_pattern_image)) {
                $files[] = $this->sourceEntry(
                    'quotation',
                    'Custom Pattern Reference',
                    $quotation->custom_pattern_image,
                    $quotation->created_at?->toDateTimeString(),
                );
            }
            if (! empty($quotation->label_design_path)) {
                $files[] = $this->sourceEntry(
                    'quotation',
                    'Label Design',
                    $quotation->label_design_path,
                    $quotation->created_at?->toDateTimeString(),
                );
            }
        }

        // 2. Per-placement artwork from print_parts_json. Prefer the order's
        //    carried-over parts; fall back to the source quotation's parts.
        $parts = $this->asArray($order->print_parts_json);
        if (empty($parts) && $quotation) {
            $parts = $this->asArray($quotation->print_parts_json);
        }
        foreach ($parts as $p) {
            if (! is_array($p)) {
                continue;
            }
            $partName = $p['part'] ?? $p['name'] ?? 'Artwork';
            $isLinkPart = ($p['image_input_type'] ?? null) === 'link';
            $raw = $isLinkPart
                ? ($p['image_link'] ?? null)
                : ($p['image'] ?? $p['image_link'] ?? null);
            if (empty($raw)) {
                continue;
            }
            $files[] = $this->sourceEntry(
                'print_part',
                "Artwork — {$partName}",
                $raw,
                null,
            );
        }

        // 3. Generic order/stage attachments (order-creation "Upload Additional
        //    Files" and any other stage uploads recorded against this order).
        $uploads = StageUpload::where('order_id', $order->id)
            ->orderBy('created_at', 'desc')
            ->get();
        foreach ($uploads as $u) {
            $entry = $this->sourceEntry(
                'stage_upload',
                $this->humanizeCategory($u->category),
                $u->file_path,
                $u->created_at?->toDateTimeString(),
            );
            // StageUpload carries richer metadata than the bare path allows.
            $entry['original_name'] = $u->original_name ?: $entry['original_name'];
            $entry['file_type']     = $u->mime_type ?: $entry['file_type'];
            $entry['uploaded_by']   = $u->uploaded_by_user_id;
            $entry['size_bytes']    = $u->size_bytes;
            $files[] = $entry;
        }

        return $files;
    }

    /**
     * Normalise one source file into a uniform read-only row. Accepts either a
     * stored disk path or an external link (http…). External links pass through
     * untouched; disk paths are resolved via publicUrl().
     *
     * @return array<string,mixed>
     */
    protected function sourceEntry(string $source, string $label, ?string $raw, ?string $createdAt): array
    {
        $isLink = is_string($raw) && str_starts_with($raw, 'http');
        $name = $raw
            ? basename(parse_url($raw, PHP_URL_PATH) ?: $raw)
            : null;

        return [
            'source'        => $source,
            'label'         => $label,
            'original_name' => $name,
            'file_type'     => $isLink ? 'link' : $this->extensionOf($name),
            'is_link'       => $isLink,
            'url'           => $raw ? ($isLink ? $raw : $this->publicUrl($raw)) : null,
            'uploaded_by'   => null,
            'size_bytes'    => null,
            'created_at'    => $createdAt,
        ];
    }

    protected function extensionOf(?string $name): ?string
    {
        if (! $name) {
            return null;
        }
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        return $ext !== '' ? strtolower($ext) : null;
    }

    protected function humanizeCategory(?string $category): string
    {
        if (! $category) {
            return 'Order Attachment';
        }
        $map = [
            'proof'      => 'Proof / Attachment',
            'reference'  => 'Reference File',
            'attachment' => 'Order Attachment',
            'additional' => 'Additional File',
            'design'     => 'Design Reference',
        ];
        return $map[$category] ?? ucwords(str_replace(['_', '-'], ' ', $category));
    }

    /** Decode a JSON-or-array column to an array. */
    protected function asArray($raw): array
    {
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
     * Build a publicly-servable URL for a stored path. Accepts paths
     * that already include the /storage/ prefix (as written by the
     * legacy GraphicEditingService) or relative disk paths.
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