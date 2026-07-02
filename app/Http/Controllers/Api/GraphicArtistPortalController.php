<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GraphicArtist\StoreDesignFile;
use App\Http\Requests\GraphicArtist\StoreLabelDesign;
use App\Http\Requests\GraphicArtist\StoreSampleUpload;
use App\Http\Requests\GraphicArtist\UpsertLabelAsset;
use App\Http\Requests\GraphicArtist\UpsertPlacement;
use App\Services\GraphicArtistPortalService;
use App\Services\OrderDesignFileService;
use App\Services\OrderDesignPlacementService;
use App\Services\OrderLabelAssetService;
use App\Services\OrderLabelDesignService;
use App\Services\SampleUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 5-H — HTTP layer for the Graphic Artist portal.
 *
 * Endpoints (all gated by portal.graphic-artist):
 *   GET    /api/v2/portal/graphic-artist/context/{orderStageId}
 *   POST   /api/v2/portal/graphic-artist/design-files     (multipart)
 *   DELETE /api/v2/portal/graphic-artist/design-files/{id}
 *   PUT    /api/v2/portal/graphic-artist/label-assets     (multipart)
 *   DELETE /api/v2/portal/graphic-artist/label-assets/{id}
 *   PUT    /api/v2/portal/graphic-artist/placements       (multipart, CP1)
 *   DELETE /api/v2/portal/graphic-artist/placements/{id}  (CP1)
 *   POST   /api/v2/portal/graphic-artist/label-design     (multipart, CP7)
 *   POST   /api/v2/portal/graphic-artist/sample-uploads   (multipart)
 *   PATCH  /api/v2/portal/graphic-artist/sample-uploads/{id}
 *   DELETE /api/v2/portal/graphic-artist/sample-uploads/{id}
 *
 * Notes + mark-as-done go through existing OrderStagesController.
 */
class GraphicArtistPortalController extends Controller
{
    public function __construct(
        protected GraphicArtistPortalService $context,
        protected OrderDesignFileService $designFiles,
        protected OrderDesignPlacementService $placements,
        protected OrderLabelAssetService $labelAssets,
        protected OrderLabelDesignService $labelDesign,
        protected SampleUploadService $sampleUploads,
    ) {
    }

    // ── Context ──────────────────────────────────────────────────────

    public function showContext(int $orderStageId)
    {
        $payload = $this->context->buildContext($orderStageId);
        return response()->json(['data' => $payload]);
    }

    // ── Design files ─────────────────────────────────────────────────

    public function storeDesignFile(StoreDesignFile $request)
    {
        $data = $request->validated();
        $file = $request->file('file');

        $relativePath = $file->store(
            "graphic-artist/designs/{$data['order_id']}",
            'public',
        );

        $created = $this->designFiles->create([
            'order_id'       => (int) $data['order_id'],
            'order_stage_id' => (int) $data['order_stage_id'],
            'kind'           => $data['kind'],
            'file_path'      => $relativePath,
            'original_name'  => $file->getClientOriginalName(),
            'mime_type'      => $file->getClientMimeType() ?: 'application/octet-stream',
            'size_bytes'     => $file->getSize() ?: 0,
            'notes'          => $data['notes'] ?? null,
        ], $request->user());

        return response()->json([
            'data' => $this->presentDesignFile($created->fresh()),
        ], 201);
    }

    public function destroyDesignFile(int $id, Request $request)
    {
        $request->validate([
            'order_stage_id' => 'required|integer|exists:order_stages,id',
        ]);

        $this->designFiles->delete(
            $id,
            (int) $request->input('order_stage_id'),
            $request->user(),
        );

        return response()->json(['message' => 'Design file deleted'], 200);
    }

    // ── Label assets ─────────────────────────────────────────────────

    public function upsertLabelAsset(UpsertLabelAsset $request)
    {
        $data = $request->validated();

        $patch = [
            'order_id'         => (int) $data['order_id'],
            'order_stage_id'   => (int) $data['order_stage_id'],
            'kind'             => $data['kind'],
            'width_in'         => $data['width_in']         ?? null,
            'height_in'        => $data['height_in']        ?? null,
            'printing_process' => $data['printing_process'] ?? null,
            'color_count'      => $data['color_count']      ?? null,
            'background_color' => $data['background_color'] ?? null,
            'material'         => $data['material']         ?? null,
            'notes'            => $data['notes']            ?? null,
        ];

        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $file = $request->file('file');
            $relativePath = $file->store(
                "graphic-artist/labels/{$data['order_id']}",
                'public',
            );
            $patch['file_path']     = $relativePath;
            $patch['original_name'] = $file->getClientOriginalName();
            $patch['mime_type']     = $file->getClientMimeType() ?: 'application/octet-stream';
            $patch['size_bytes']    = $file->getSize() ?: 0;
        }

        $asset = $this->labelAssets->upsert($patch, $request->user());

        return response()->json([
            'data' => $this->presentLabelAsset($asset->fresh()),
        ]);
    }

    public function destroyLabelAsset(int $id, Request $request)
    {
        $request->validate([
            'order_stage_id' => 'required|integer|exists:order_stages,id',
        ]);

        $this->labelAssets->delete(
            $id,
            (int) $request->input('order_stage_id'),
            $request->user(),
        );

        return response()->json(['message' => 'Label asset deleted'], 200);
    }

    // ── Shared Label Design (GA Portal CP7) ──────────────────────────
    // One file covers Brand + Care/Size labels — same column + folder Add
    // Order uses (orders.label_design_path, order-label-designs/).

    public function storeLabelDesign(StoreLabelDesign $request)
    {
        $data = $request->validated();
        $file = $request->file('file');

        $relativePath = $file->store('order-label-designs', 'public');

        $order = $this->labelDesign->upload([
            'order_id'       => (int) $data['order_id'],
            'order_stage_id' => (int) $data['order_stage_id'],
            'file_path'      => $relativePath,
            'original_name'  => $file->getClientOriginalName(),
        ], $request->user());

        return response()->json([
            'data' => [
                'order_id'          => $order->id,
                'label_design_path' => $order->label_design_path,
                'label_design_url'  => Storage::disk('public')->url($order->label_design_path),
            ],
        ], 201);
    }

    // ── Placements (GA Portal CP1) ───────────────────────────────────

    public function upsertPlacement(UpsertPlacement $request)
    {
        $data = $request->validated();

        if ($request->hasFile('artwork') && $request->file('artwork')->isValid()) {
            $data['artwork_path'] = $request->file('artwork')->store(
                "graphic-artist/placements/{$data['order_id']}",
                'public',
            );
        }
        unset($data['artwork']);

        $placement = $this->placements->upsert($data, $request->user());

        return response()->json([
            'data' => $this->placements->present($placement),
        ]);
    }

    public function destroyPlacement(int $id, Request $request)
    {
        $request->validate([
            'order_stage_id' => 'required|integer|exists:order_stages,id',
        ]);

        $this->placements->delete(
            $id,
            (int) $request->input('order_stage_id'),
            $request->user(),
        );

        return response()->json(['message' => 'Placement deleted'], 200);
    }

    // ── Sample uploads ───────────────────────────────────────────────

    public function storeSampleUpload(StoreSampleUpload $request)
    {
        $data = $request->validated();

        if ($request->hasFile('photo_front') && $request->file('photo_front')->isValid()) {
            $data['photo_front_path'] = $request->file('photo_front')
                ->store('sample-uploads/front', 'public');
        }
        if ($request->hasFile('photo_back') && $request->file('photo_back')->isValid()) {
            $data['photo_back_path'] = $request->file('photo_back')
                ->store('sample-uploads/back', 'public');
        }
        unset($data['photo_front'], $data['photo_back']);

        $upload = $this->sampleUploads->create($data, $request->user());

        return response()->json([
            'data' => $this->presentSampleUpload($upload->fresh()),
        ], 201);
    }

    public function updateSampleUpload(int $id, Request $request)
    {
        $data = $request->validate([
            'remarks'       => 'nullable|string|max:1000',
            'sample_status' => 'nullable|in:pending,for_approval',
            'photo_front'   => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
            'photo_back'    => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($request->hasFile('photo_front') && $request->file('photo_front')->isValid()) {
            $data['photo_front_path'] = $request->file('photo_front')
                ->store('sample-uploads/front', 'public');
        }
        if ($request->hasFile('photo_back') && $request->file('photo_back')->isValid()) {
            $data['photo_back_path'] = $request->file('photo_back')
                ->store('sample-uploads/back', 'public');
        }
        unset($data['photo_front'], $data['photo_back']);

        $upload = $this->sampleUploads->update($id, $data, $request->user());

        return response()->json([
            'data' => $this->presentSampleUpload($upload),
        ]);
    }

    public function destroySampleUpload(int $id, Request $request)
    {
        $this->sampleUploads->delete($id, $request->user());
        return response()->json(['message' => 'Sample upload deleted'], 200);
    }

    // ── Presenters ──────────────────────────────────────────────────

    protected function presentDesignFile($f): array
    {
        return [
            'id'             => $f->id,
            'order_id'       => $f->order_id,
            'kind'           => $f->kind,
            'version'        => (int) $f->version,
            'is_latest'      => (bool) $f->is_latest,
            'file_path'      => $f->file_path,
            'file_url'       => $f->file_path
                ? Storage::disk('public')->url($f->file_path)
                : null,
            'original_name'  => $f->original_name,
            'mime_type'      => $f->mime_type,
            'size_bytes'     => (int) $f->size_bytes,
            'notes'          => $f->notes,
            'uploaded_by'    => $f->uploaded_by_user_id,
            'created_at'     => $f->created_at?->toDateTimeString(),
        ];
    }

    protected function presentLabelAsset($a): array
    {
        return [
            'id'                => $a->id,
            'order_id'          => $a->order_id,
            'kind'              => $a->kind,
            'file_path'         => $a->file_path,
            'file_url'          => $a->file_path
                ? Storage::disk('public')->url($a->file_path)
                : null,
            'original_name'     => $a->original_name,
            'mime_type'         => $a->mime_type,
            'size_bytes'        => $a->size_bytes ? (int) $a->size_bytes : null,
            'width_in'          => $a->width_in !== null ? (float) $a->width_in : null,
            'height_in'         => $a->height_in !== null ? (float) $a->height_in : null,
            'printing_process'  => $a->printing_process,
            'color_count'       => $a->color_count !== null ? (int) $a->color_count : null,
            'background_color'  => $a->background_color,
            'material'          => $a->material,
            'notes'             => $a->notes,
            'uploaded_by'       => $a->uploaded_by_user_id,
            'created_at'        => $a->created_at?->toDateTimeString(),
            'updated_at'        => $a->updated_at?->toDateTimeString(),
        ];
    }

    protected function presentSampleUpload($u): array
    {
        return [
            'id'                => $u->id,
            'order_id'          => $u->order_id,
            'order_stage_id'    => $u->order_stage_id,
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
            'created_at'        => $u->created_at?->toDateTimeString(),
        ];
    }
}
