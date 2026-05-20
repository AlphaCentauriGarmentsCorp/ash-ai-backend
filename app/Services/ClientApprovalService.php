<?php

namespace App\Services;

use App\Models\ClientApproval;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ClientApprovalService — record + respond to client approval events.
 *
 * Two write paths:
 *   - record()  — CSR opens an approval request (status starts 'waiting')
 *   - respond() — CSR records the client's response (approve / revise / reject)
 *
 * The state machine is:
 *   waiting → approved | revision_requested | rejected
 *
 * `revision_requested` is non-terminal at the workflow level (CSR will
 * upload a new mockup/sample and create a new ClientApproval row) but
 * the row itself doesn't get further transitions — it's frozen as
 * historical record of that revision request.
 */
class ClientApprovalService
{
    public function __construct(
        protected CsrActivityLogger $logger,
    ) {}

    /**
     * List approvals with optional filters.
     *
     * @param array{order_id?: int, kind?: string, status?: string} $filters
     */
    public function list(array $filters = []): Collection
    {
        $q = ClientApproval::with(['order', 'requestedBy', 'recordedBy']);

        if (!empty($filters['order_id'])) {
            $q->where('order_id', $filters['order_id']);
        }
        if (!empty($filters['kind'])) {
            $q->where('kind', $filters['kind']);
        }
        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->orderByDesc('created_at')->get();
    }

    /**
     * Open a new approval request.
     *
     * Status starts 'waiting'. `requested_at` is set to now().
     */
    public function record(int $orderId, array $data, ?UploadedFile $screenshot = null): ClientApproval
    {
        return DB::transaction(function () use ($orderId, $data, $screenshot) {
            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($orderId);

            $screenshotPath = null;
            if ($screenshot !== null) {
                $screenshotPath = $screenshot->store("csr/approvals/{$order->id}", 'public');
            }

            $approval = ClientApproval::create([
                'order_id'             => $order->id,
                'kind'                 => $data['kind'],
                'status'               => ClientApproval::STATUS_WAITING,
                'requested_at'         => now(),
                'screenshot_path'      => $screenshotPath,
                'internal_notes'       => $data['internal_notes'] ?? null,
                'requested_by_user_id' => Auth::id(),
            ]);

            $this->logger->log(
                action: 'approval.requested',
                summary: "Approval requested ({$approval->kind}) for order #{$order->id}",
                subject: $approval,
                orderId: $order->id,
                clientId: $order->client_id,
                data: ['kind' => $approval->kind],
            );

            return $approval->fresh(['order', 'requestedBy', 'recordedBy']);
        });
    }

    /**
     * Record the client's response to a pending approval.
     *
     * @param string $decision 'approved' | 'revision_requested' | 'rejected'
     */
    public function respond(int $approvalId, string $decision, array $data = [], ?UploadedFile $screenshot = null): ClientApproval
    {
        if (!in_array($decision, [
            ClientApproval::STATUS_APPROVED,
            ClientApproval::STATUS_REVISION_REQUESTED,
            ClientApproval::STATUS_REJECTED,
        ], true)) {
            throw ValidationException::withMessages([
                'decision' => ['Decision must be approved, revision_requested, or rejected.'],
            ]);
        }

        return DB::transaction(function () use ($approvalId, $decision, $data, $screenshot) {
            /** @var ClientApproval $approval */
            $approval = ClientApproval::lockForUpdate()->findOrFail($approvalId);

            if ($approval->status !== ClientApproval::STATUS_WAITING) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Approval is in status '{$approval->status}'. Only 'waiting' rows can be responded to.",
                    ],
                ]);
            }

            // If a new screenshot is attached on response (e.g. screenshot
            // of the client's "approved!" reply), overlay the existing.
            $screenshotPath = $approval->screenshot_path;
            if ($screenshot !== null) {
                if ($screenshotPath !== null) {
                    Storage::disk('public')->delete($screenshotPath);
                }
                $screenshotPath = $screenshot->store("csr/approvals/{$approval->order_id}", 'public');
            }

            $fromStatus = $approval->status;

            $approval->update([
                'status'                => $decision,
                'responded_at'          => now(),
                'client_response_notes' => $data['client_response_notes'] ?? null,
                'internal_notes'        => $data['internal_notes']        ?? $approval->internal_notes,
                'screenshot_path'       => $screenshotPath,
                'recorded_by_user_id'   => Auth::id(),
            ]);

            $this->logger->log(
                action: 'approval.responded',
                summary: "Approval ({$approval->kind}) → {$decision}",
                subject: $approval,
                orderId: $approval->order_id,
                clientId: optional($approval->order)->client_id,
                data: [
                    'from_status' => $fromStatus,
                    'to_status'   => $decision,
                    'kind'        => $approval->kind,
                ],
            );

            return $approval->fresh(['order', 'requestedBy', 'recordedBy']);
        });
    }

    /**
     * Build the presenter shape — includes public screenshot URL.
     */
    public function present(ClientApproval $approval): array
    {
        return [
            'id'                    => $approval->id,
            'order_id'              => $approval->order_id,
            'kind'                  => $approval->kind,
            'status'                => $approval->status,
            'requested_at'          => optional($approval->requested_at)->toIso8601String(),
            'responded_at'          => optional($approval->responded_at)->toIso8601String(),
            'screenshot_path'       => $approval->screenshot_path,
            'screenshot_url'        => $approval->screenshot_path
                ? Storage::disk('public')->url($approval->screenshot_path)
                : null,
            'client_response_notes' => $approval->client_response_notes,
            'internal_notes'        => $approval->internal_notes,
            'requested_by_user_id'  => $approval->requested_by_user_id,
            'recorded_by_user_id'   => $approval->recorded_by_user_id,
            'created_at'            => $approval->created_at?->toIso8601String(),
        ];
    }
}
