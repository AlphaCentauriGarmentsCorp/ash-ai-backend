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
            'placements'          => $this->placements($design),
            'pantones_used'       => $this->pantonesUsed($design),
            'placement_options'   => $this->placementOptions(),
            'measurement_options' => $this->measurementOptions(),
            'label_assets'        => $this->labelAssets($order),
            'screen_details'      => $this->screenDetails($order),
            'sample_uploads'      => $this->sampleUploads($stage),
            'material_requests'   => $this->materialRequestsForStage($stage),
            'activity_log'        => $this->recentActivity($stage, 15),
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
            'id'              => $order->id,
            'po_code'         => $order->po_code,
            'client_name'     => $order->client_name,
            'client_brand'    => $order->client_brand,
            'shirt_color'     => $order->shirt_color,
            'special_print'   => $order->special_print,
            'print_area'      => $order->print_area,
            'total_pcs'       => $totalPcs,
            'workflow_status' => $order->workflow_status,
            'notes'           => $order->notes,
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
                            'id'           => $rec->id,
                            'name'         => $rec->name,
                            'hexcolor'     => $rec->hexcolor,
                            'pantone_code' => $rec->pantone_code,
                        ];
                    }
                } elseif (is_array($entry)) {
                    // Already-inline descriptor — preserve shape.
                    $hydrated[] = [
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

    // ── Helpers ────────────────────────────────────────────────────

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
