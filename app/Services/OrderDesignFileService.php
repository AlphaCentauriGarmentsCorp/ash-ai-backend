<?php

namespace App\Services;

use App\Models\OrderDesign;
use App\Models\OrderDesignFile;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-H — Versioned design file management.
 *
 * Each upload bumps the per-(order_id, kind) version counter and flips
 * is_latest=true on the new row + false on the previous latest.
 *
 * On delete: hard-removes the file from public storage (per decision D8)
 * and removes the row. If the deleted row was is_latest=true, the next-
 * highest version (if any) is promoted to is_latest=true so the UI
 * always shows "Latest" for the remaining files of that kind.
 *
 * Every create/delete writes a row to stage_audit_logs using
 * domain-specific action labels:
 *   - design_file.uploaded
 *   - design_file.deleted
 */
class OrderDesignFileService
{
    public const AUDIT_UPLOADED = 'design_file.uploaded';
    public const AUDIT_DELETED  = 'design_file.deleted';

    /**
     * Create a new design file. Bumps version, flips is_latest, audit-logs.
     *
     * @param array{
     *   order_id:int,
     *   order_stage_id:int,
     *   kind:string,
     *   file_path:string,
     *   original_name:string,
     *   mime_type:string,
     *   size_bytes:int,
     *   notes?:string|null,
     * } $data
     */
    public function create(array $data, ?User $actor = null): OrderDesignFile
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        if (! in_array($data['kind'], OrderDesignFile::KINDS, true)) {
            throw ValidationException::withMessages([
                'kind' => "Invalid design file kind '{$data['kind']}'.",
            ]);
        }

        return DB::transaction(function () use ($data, $actor) {
            $stage = $this->loadActiveGraphicStage(
                $data['order_stage_id'],
                $data['order_id'],
            );

            // Find the existing latest row for this (order, kind), if any.
            $previousLatest = OrderDesignFile::where('order_id', $data['order_id'])
                ->where('kind', $data['kind'])
                ->where('is_latest', true)
                ->lockForUpdate()
                ->first();

            // New version = (max + 1) per (order_id, kind).
            $maxVersion = (int) OrderDesignFile::where('order_id', $data['order_id'])
                ->where('kind', $data['kind'])
                ->max('version');

            // Demote the previous latest, if any.
            if ($previousLatest) {
                $previousLatest->update(['is_latest' => false]);
            }

            // Find the design row (optional FK).
            $design = OrderDesign::where('order_id', $data['order_id'])->first();

            $file = OrderDesignFile::create([
                'order_id'            => $data['order_id'],
                'order_design_id'     => $design?->id,
                'kind'                => $data['kind'],
                'version'             => $maxVersion + 1,
                'file_path'           => $data['file_path'],
                'original_name'       => $data['original_name'],
                'mime_type'           => $data['mime_type'],
                'size_bytes'          => (int) $data['size_bytes'],
                'is_latest'           => true,
                'uploaded_by_user_id' => $actor->id,
                'notes'               => $data['notes'] ?? null,
            ]);

            StageAuditLog::create([
                'order_id'       => $data['order_id'],
                'order_stage_id' => $stage->id,
                'user_id'        => $actor->id,
                'action'         => self::AUDIT_UPLOADED,
                'from_status'    => null,
                'to_status'      => null,
                'notes'          => "{$data['kind']} v{$file->version}: {$data['original_name']}",
                'created_at'     => now(),
            ]);

            return $file;
        });
    }

    /**
     * Hard-delete a design file: removes the row, deletes the physical
     * file from public storage, and promotes the next-highest version
     * of the same kind to is_latest if the deleted row was latest.
     */
    public function delete(int $fileId, int $orderStageId, ?User $actor = null): void
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        DB::transaction(function () use ($fileId, $orderStageId, $actor) {
            $file = OrderDesignFile::lockForUpdate()->find($fileId);
            if (! $file) {
                throw ValidationException::withMessages([
                    'id' => 'Design file not found.',
                ]);
            }

            $stage = $this->loadActiveGraphicStage($orderStageId, $file->order_id);

            $wasLatest = (bool) $file->is_latest;
            $orderId   = $file->order_id;
            $kind      = $file->kind;
            $version   = $file->version;
            $name      = $file->original_name;
            $path      = $file->file_path;

            // Drop the row.
            $file->delete();

            // Hard-delete the physical file (D8). storage path may be
            // prefixed with /storage/ (the public URL) or be a relative
            // disk path — handle both.
            $this->deletePhysicalFile($path);

            // Promote the next-highest version of the same kind, if any.
            if ($wasLatest) {
                $nextLatest = OrderDesignFile::where('order_id', $orderId)
                    ->where('kind', $kind)
                    ->orderBy('version', 'desc')
                    ->first();
                if ($nextLatest) {
                    $nextLatest->update(['is_latest' => true]);
                }
            }

            StageAuditLog::create([
                'order_id'       => $orderId,
                'order_stage_id' => $stage->id,
                'user_id'        => $actor->id,
                'action'         => self::AUDIT_DELETED,
                'from_status'    => null,
                'to_status'      => null,
                'notes'          => "{$kind} v{$version}: {$name}",
                'created_at'     => now(),
            ]);
        });
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Validates that the stage exists, belongs to the order, is in an
     * active state, and is the graphic_artwork stage. Returns it.
     */
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

        $activeStatuses = [
            OrderStage::STATUS_IN_PROGRESS,
            OrderStage::STATUS_FOR_APPROVAL,
            OrderStage::STATUS_DELAYED,
        ];

        if (! in_array($stage->status, $activeStatuses, true)) {
            throw ValidationException::withMessages([
                'order_stage_id' => "Cannot modify design files against a stage in status '{$stage->status}'.",
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
                'permission' => 'You do not have permission to upload design files.',
            ]);
        }
    }

    /**
     * Deletes the file from the public disk. Path may be either:
     *   - "graphic-artist/designs/1/abc.png"            (disk-relative)
     *   - "/storage/graphic-artist/designs/1/abc.png"   (public URL form)
     *
     * Strips the /storage/ prefix if present, then deletes via the
     * public disk. Silent on missing files — the row is gone either way.
     */
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
