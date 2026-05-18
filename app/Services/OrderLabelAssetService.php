<?php

namespace App\Services;

use App\Models\OrderLabelAsset;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-H — Label / tag asset management.
 *
 * Upsert pattern: one row per (order_id, kind). If a row already exists,
 * any supplied fields are patched in; otherwise a new row is created.
 *
 * Re-uploading a file replaces the previous physical file on the public
 * disk (the old file is deleted). Metadata-only updates leave the file
 * alone.
 *
 * Audit log actions:
 *   - label_asset.upserted
 *   - label_asset.deleted
 */
class OrderLabelAssetService
{
    public const AUDIT_UPSERTED = 'label_asset.upserted';
    public const AUDIT_DELETED  = 'label_asset.deleted';

    /**
     * Insert-or-update a label asset for an order + kind.
     *
     * @param array{
     *   order_id:int,
     *   order_stage_id:int,
     *   kind:string,
     *   file_path?:string|null,
     *   original_name?:string|null,
     *   mime_type?:string|null,
     *   size_bytes?:int|null,
     *   width_in?:float|null,
     *   height_in?:float|null,
     *   printing_process?:string|null,
     *   color_count?:int|null,
     *   background_color?:string|null,
     *   material?:string|null,
     *   notes?:string|null,
     * } $data
     */
    public function upsert(array $data, ?User $actor = null): OrderLabelAsset
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        if (! in_array($data['kind'], OrderLabelAsset::KINDS, true)) {
            throw ValidationException::withMessages([
                'kind' => "Invalid label kind '{$data['kind']}'.",
            ]);
        }

        if (
            isset($data['printing_process'])
            && ! is_null($data['printing_process'])
            && ! in_array($data['printing_process'], OrderLabelAsset::PRINTING_PROCESSES, true)
        ) {
            throw ValidationException::withMessages([
                'printing_process' => "Invalid printing process '{$data['printing_process']}'.",
            ]);
        }

        return DB::transaction(function () use ($data, $actor) {
            $stage = $this->loadActiveGraphicStage(
                $data['order_stage_id'],
                $data['order_id'],
            );

            $existing = OrderLabelAsset::where('order_id', $data['order_id'])
                ->where('kind', $data['kind'])
                ->lockForUpdate()
                ->first();

            // Whitelist fields we patch (drops order_stage_id from $data).
            $patchableFields = [
                'file_path', 'original_name', 'mime_type', 'size_bytes',
                'width_in', 'height_in', 'printing_process',
                'color_count', 'background_color', 'material', 'notes',
            ];

            $patch = [];
            foreach ($patchableFields as $f) {
                if (array_key_exists($f, $data)) {
                    $patch[$f] = $data[$f];
                }
            }

            if ($existing) {
                // If a new file is being attached, delete the previous one.
                if (
                    array_key_exists('file_path', $patch)
                    && $patch['file_path']
                    && $existing->file_path
                    && $existing->file_path !== $patch['file_path']
                ) {
                    $this->deletePhysicalFile($existing->file_path);
                }
                $patch['uploaded_by_user_id'] = $actor->id;
                $existing->update($patch);
                $asset = $existing->fresh();
            } else {
                $asset = OrderLabelAsset::create(array_merge($patch, [
                    'order_id'            => $data['order_id'],
                    'kind'                => $data['kind'],
                    'uploaded_by_user_id' => $actor->id,
                ]));
            }

            StageAuditLog::create([
                'order_id'       => $data['order_id'],
                'order_stage_id' => $stage->id,
                'user_id'        => $actor->id,
                'action'         => self::AUDIT_UPSERTED,
                'from_status'    => null,
                'to_status'      => null,
                'notes'          => $data['kind'] . ($asset->original_name
                    ? ': ' . $asset->original_name
                    : ' (metadata)'),
                'created_at'     => now(),
            ]);

            return $asset;
        });
    }

    /**
     * Delete a label asset. Removes the row + the physical file.
     */
    public function delete(int $assetId, int $orderStageId, ?User $actor = null): void
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        DB::transaction(function () use ($assetId, $orderStageId, $actor) {
            $asset = OrderLabelAsset::lockForUpdate()->find($assetId);
            if (! $asset) {
                throw ValidationException::withMessages([
                    'id' => 'Label asset not found.',
                ]);
            }

            $stage = $this->loadActiveGraphicStage($orderStageId, $asset->order_id);

            $orderId = $asset->order_id;
            $kind    = $asset->kind;
            $name    = $asset->original_name;
            $path    = $asset->file_path;

            $asset->delete();
            $this->deletePhysicalFile($path);

            StageAuditLog::create([
                'order_id'       => $orderId,
                'order_stage_id' => $stage->id,
                'user_id'        => $actor->id,
                'action'         => self::AUDIT_DELETED,
                'from_status'    => null,
                'to_status'      => null,
                'notes'          => $kind . ($name ? ": {$name}" : ''),
                'created_at'     => now(),
            ]);
        });
    }

    // ── Helpers ────────────────────────────────────────────────────

    protected function loadActiveGraphicStage(int $stageId, int $expectedOrderId): OrderStage
    {
        $stage = OrderStage::find($stageId);
        if (! $stage) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage not found.',
            ]);
        }
        if ((int) $stage->order_id !== (int) $expectedOrderId) {
            throw ValidationException::withMessages([
                'order_stage_id' => 'Stage does not belong to that order.',
            ]);
        }
        if ($stage->stage !== 'graphic_artwork') {
            throw ValidationException::withMessages([
                'order_stage_id' => "Stage '{$stage->stage}' is not a graphic artist portal stage.",
            ]);
        }
        $active = [
            OrderStage::STATUS_IN_PROGRESS,
            OrderStage::STATUS_FOR_APPROVAL,
            OrderStage::STATUS_DELAYED,
        ];
        if (! in_array($stage->status, $active, true)) {
            throw ValidationException::withMessages([
                'order_stage_id' => "Cannot modify label assets against a stage in status '{$stage->status}'.",
            ]);
        }
        return $stage;
    }

    protected function ensureCan(?User $actor): void
    {
        if (! $actor) {
            throw ValidationException::withMessages([
                'actor' => 'No authenticated user.',
            ]);
        }
        if (! $actor->can('action.upload-photos')) {
            throw ValidationException::withMessages([
                'permission' => 'You do not have permission to manage label assets.',
            ]);
        }
    }

    protected function deletePhysicalFile(?string $path): void
    {
        if (! $path) {
            return;
        }
        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }
        Storage::disk('public')->delete($relative);
    }
}
