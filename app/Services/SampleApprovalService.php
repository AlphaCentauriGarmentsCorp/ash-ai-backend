<?php

namespace App\Services;

use App\Models\ClientApproval;
use App\Models\Order;
use App\Models\OrderStage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SampleApprovalService — the CSR sample-approval decision (Phase 3).
 *
 * After Sample Packing the order auto-advances to the `sample_approval`
 * stage (CSR-owned) and CSR is alerted via the existing stage.in_progress
 * notification (every stage row carries assigned_role, so this fires for
 * free). CSR then shepherds the client's verdict here:
 *
 *   approve → the sample_approval stage completes and the order advances to
 *             Payment Verification (Mass); ensureGatePayment (inside
 *             OrderStagesService::markComplete) auto-creates the 60%
 *             downpayment stub on the Dashboard awaiting list.
 *
 *   reject  → the whole sample sub-flow loops back to graphic_artwork
 *             (OrderStagesService::resetSampleSubflow) so the artwork can be
 *             reworked and the sample re-made. No second sample fee, and the
 *             Graphic Artist is alerted via the re-promotion notification.
 *
 * Each decision is ALSO recorded as a ClientApproval(kind=sample) row so the
 * client's verdict + screenshot + notes are preserved as audit history,
 * independent of the workflow row that gets reused on a loop-back.
 *
 * The STAGE is the source of truth (drives queues + the timeline); the
 * ClientApproval log is the evidence layer.
 */
class SampleApprovalService
{
    private const STAGE = 'sample_approval';

    public function __construct(
        protected OrderStagesService $stages,
        protected CsrActivityLogger $logger,
    ) {}

    /**
     * Orders sitting at the sample_approval stage, awaiting CSR's decision —
     * the "Samples for Approval" worklist (mirrors the awaiting-payment list).
     *
     * @return array<int, array<string, mixed>>
     */
    public function awaitingQueue(): array
    {
        $rows = OrderStage::query()
            ->where('stage', self::STAGE)
            ->where('status', OrderStage::STATUS_IN_PROGRESS)
            ->with('order')
            ->orderBy('started_at')
            ->get();

        return $rows
            ->filter(fn (OrderStage $s) => $s->order !== null)
            ->map(fn (OrderStage $s) => $this->presentQueueRow($s))
            ->values()
            ->all();
    }

    /** Badge count for the worklist. */
    public function awaitingCount(): int
    {
        return OrderStage::where('stage', self::STAGE)
            ->where('status', OrderStage::STATUS_IN_PROGRESS)
            ->count();
    }

    /**
     * Record CSR's sample-approval decision and drive the workflow.
     *
     * @param string                $decision 'approved' | 'rejected'
     * @param array<string, mixed>  $data     client_response_notes / internal_notes
     *
     * @return array{approval: ClientApproval, order: Order, outcome: string, next_stage: ?string}
     *
     * @throws ValidationException 422 when the order isn't at sample_approval,
     *                                 the decision is invalid, or a reject has
     *                                 no reason.
     */
    public function decide(
        int $orderId,
        string $decision,
        array $data = [],
        ?UploadedFile $screenshot = null,
    ): array {
        if (! in_array($decision, [ClientApproval::STATUS_APPROVED, ClientApproval::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'decision' => ['Decision must be either approved or rejected.'],
            ]);
        }

        // A reject must carry a reason so the Graphic Artist knows what to fix.
        if ($decision === ClientApproval::STATUS_REJECTED
            && empty($data['client_response_notes'])
            && empty($data['internal_notes'])) {
            throw ValidationException::withMessages([
                'client_response_notes' => ['A reason is required when rejecting a sample.'],
            ]);
        }

        return DB::transaction(function () use ($orderId, $decision, $data, $screenshot) {
            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($orderId);

            // Guard: the order must actually be sitting at sample_approval.
            $stage = OrderStage::where('order_id', $order->id)
                ->where('stage', self::STAGE)
                ->lockForUpdate()
                ->first();

            if (! $stage || $stage->status !== OrderStage::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'order' => ['This order is not awaiting sample approval.'],
                ])->status(422);
            }

            // Persist the client's verdict as audit history (kind=sample).
            $screenshotPath = null;
            if ($screenshot !== null) {
                $screenshotPath = $screenshot->store("csr/approvals/{$order->id}", 'public');
            }

            $approval = ClientApproval::create([
                'order_id'              => $order->id,
                'kind'                  => ClientApproval::KIND_SAMPLE,
                'status'                => $decision,
                'requested_at'          => now(),
                'responded_at'          => now(),
                'screenshot_path'       => $screenshotPath,
                'client_response_notes' => $data['client_response_notes'] ?? null,
                'internal_notes'        => $data['internal_notes'] ?? null,
                'requested_by_user_id'  => Auth::id(),
                'recorded_by_user_id'   => Auth::id(),
            ]);

            // Drive the workflow.
            if ($decision === ClientApproval::STATUS_APPROVED) {
                $next = $this->stages->markComplete(
                    $stage->id,
                    $data['internal_notes'] ?? 'Sample approved by client.',
                );
                $outcome   = 'advanced';
                $nextStage = $next?->stage;
            } else {
                $reason   = $data['client_response_notes'] ?? $data['internal_notes'] ?? 'Sample rejected by client.';
                $promoted = $this->stages->resetSampleSubflow($order, $reason);
                $outcome   = 'looped_back';
                $nextStage = $promoted[0]->stage ?? null;
            }

            $this->logger->log(
                action: $decision === ClientApproval::STATUS_APPROVED
                    ? 'sample_approval.approved'
                    : 'sample_approval.rejected',
                summary: $decision === ClientApproval::STATUS_APPROVED
                    ? "Sample approved \u{2192} {$nextStage}"
                    : 'Sample rejected \u{2192} looped back to graphic_artwork',
                subject: $approval,
                orderId: $order->id,
                clientId: $order->client_id,
                data: ['decision' => $decision, 'next_stage' => $nextStage],
            );

            return [
                'approval'   => $approval->fresh(),
                'order'      => $order->fresh(),
                'outcome'    => $outcome,
                'next_stage' => $nextStage,
            ];
        });
    }

    /** Row shape for the worklist (keys mirror the awaiting-payment row). */
    private function presentQueueRow(OrderStage $stage): array
    {
        $order     = $stage->order;
        $startedAt = $stage->started_at;

        return [
            'order_id'        => $order->id,
            'order_stage_id'  => $stage->id,
            'project_no'      => $order->po_code,
            'po_code'         => $order->po_code,
            'client'          => $order->client_name,
            'client_name'     => $order->client_name,
            'brand'           => $order->client_brand,
            'client_brand'    => $order->client_brand,
            'workflow_status' => $order->workflow_status,
            'started_at'      => $startedAt?->toIso8601String(),
            'waiting_seconds' => $startedAt ? $startedAt->diffInSeconds(now()) : null,
        ];
    }
}
