<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * GA Portal CP7 — the shared Label Design upload, GA-side.
 *
 * Add Order stores ONE label-design artwork shared by the Brand Label and
 * the Care/Size Label (`orders.label_design_path`, files under
 * order-label-designs/ on the public disk — see
 * OrdersController::resolveLabelDesign). This service gives the Graphic
 * Artist the same write: upload/replace that one shared file from the
 * portal, guarded by the usual active graphic_artwork stage + permission
 * checks. The order page, Add Order edit form, and the Review Hub all
 * read the same column, so everything stays in sync.
 *
 * Replacing hard-deletes the previous PHYSICAL file only when the old
 * value was a disk path — an external link (http…) recorded at order
 * creation is left alone.
 *
 * Audit action (stage_audit_logs): label_design.uploaded
 */
class OrderLabelDesignService
{
    public const AUDIT_UPLOADED = 'label_design.uploaded';

    /**
     * Set/replace the order's shared label design.
     *
     * @param array{
     *   order_id:int,
     *   order_stage_id:int,
     *   file_path:string,       // already stored on the public disk
     *   original_name:string,
     * } $data
     */
    public function upload(array $data, ?User $actor = null): Order
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor);

        return DB::transaction(function () use ($data, $actor) {
            $stage = $this->loadActiveGraphicStage(
                (int) $data['order_stage_id'],
                (int) $data['order_id'],
            );

            $order = Order::lockForUpdate()->find((int) $data['order_id']);
            if (! $order) {
                throw ValidationException::withMessages([
                    'order_id' => 'Order not found.',
                ]);
            }

            $previous = $order->label_design_path;
            $order->update(['label_design_path' => $data['file_path']]);

            // Hard-delete the replaced physical file (D8), but never touch
            // an external link.
            if ($previous && ! str_starts_with(trim($previous), 'http')) {
                $this->deletePhysicalFile($previous);
            }

            StageAuditLog::create([
                'order_id'       => $order->id,
                'order_stage_id' => $stage->id,
                'user_id'        => $actor->id,
                'action'         => self::AUDIT_UPLOADED,
                'from_status'    => null,
                'to_status'      => null,
                'notes'          => $data['original_name'],
                'created_at'     => now(),
            ]);

            return $order->fresh();
        });
    }

    // ── Helpers (same guards as the other GA write services) ────────

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
                'order_stage_id' => "Cannot modify the label design against a stage in status '{$stage->status}'.",
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
                'permission' => 'You do not have permission to upload the label design.',
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
