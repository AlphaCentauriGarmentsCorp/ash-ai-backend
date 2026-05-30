<?php

namespace App\Services;

use App\Models\OrderStage;
use App\Models\StageUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 3 — generic per-stage proof-of-work uploads.
 *
 * Stores attachments on the 'public' disk under stage-uploads/ (same
 * convention as the other upload features) and exposes simple list/summarize
 * helpers the Review Hub uses to render artifacts per stage.
 */
class StageUploadService
{
    /**
     * Store one uploaded file against a stage.
     */
    public function store(
        int $stageId,
        User $uploader,
        UploadedFile $file,
        string $category = 'proof',
        ?string $notes = null,
    ): StageUpload {
        /** @var OrderStage $stage */
        $stage = OrderStage::findOrFail($stageId);

        $path = $file->store('stage-uploads', 'public');

        return StageUpload::create([
            'order_id'            => $stage->order_id,
            'order_stage_id'      => $stage->id,
            'uploaded_by_user_id' => $uploader->id,
            'category'            => $category ?: 'proof',
            'file_path'           => $path,
            'original_name'       => $file->getClientOriginalName(),
            'mime_type'           => $file->getClientMimeType(),
            'size_bytes'          => $file->getSize(),
            'notes'               => $notes,
        ]);
    }

    /**
     * Delete an attachment (and its underlying file).
     */
    public function delete(int $uploadId): void
    {
        $upload = StageUpload::find($uploadId);
        if (! $upload) {
            return;
        }

        if ($upload->file_path && Storage::disk('public')->exists($upload->file_path)) {
            Storage::disk('public')->delete($upload->file_path);
        }

        $upload->delete();
    }

    /**
     * All attachments for a stage, newest first.
     *
     * @return Collection<int, array>
     */
    public function forStage(int $stageId): Collection
    {
        return StageUpload::where('order_stage_id', $stageId)
            ->with('uploadedBy:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($u) => $this->summarize($u));
    }

    /**
     * All attachments for an order, grouped by order_stage_id. Powers the
     * Review Hub's per-stage artifact rendering.
     *
     * @return Collection<int, array> keyed by order_stage_id
     */
    public function forOrderGrouped(int $orderId): Collection
    {
        return StageUpload::where('order_id', $orderId)
            ->with('uploadedBy:id,name')
            ->orderByDesc('id')
            ->get()
            ->groupBy('order_stage_id')
            ->map(fn ($rows) => $rows->map(fn ($u) => $this->summarize($u))->values());
    }

    /**
     * Compact representation for API responses.
     */
    public function summarize(StageUpload $upload): array
    {
        return [
            'id'            => $upload->id,
            'order_id'      => $upload->order_id,
            'order_stage_id' => $upload->order_stage_id,
            'category'      => $upload->category,
            'file_path'     => $upload->file_path,
            'url'           => $upload->file_path
                ? asset('storage/' . $upload->file_path)
                : null,
            'original_name' => $upload->original_name,
            'mime_type'     => $upload->mime_type,
            'is_image'      => $upload->isImage(),
            'size_bytes'    => $upload->size_bytes,
            'notes'         => $upload->notes,
            'uploaded_by'   => $upload->relationLoaded('uploadedBy') && $upload->uploadedBy
                ? ['id' => $upload->uploadedBy->id, 'name' => $upload->uploadedBy->name]
                : ['id' => $upload->uploaded_by_user_id, 'name' => null],
            'created_at'    => $upload->created_at?->toDateTimeString(),
        ];
    }
}
