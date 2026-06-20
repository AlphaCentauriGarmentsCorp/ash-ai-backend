<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderStage;
use App\Support\WorkflowStages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * PendingApprovalsService — powers the Dashboard "Pending Approvals" queue
 * (ASH AI Change Request 2026-06-02, Change 1B).
 *
 * The only blocking approval gates are the three Payment Verification stages
 * (sample fee #4, 60% DP #9, 40% balance). The unit of work awaiting approval
 * is therefore an OrderPayment sitting in `for_verification` — CSR uploaded the
 * proof, and Finance / Superadmin / Admin must confirm the money landed.
 *
 * Approving here is the same integrity-gated action as the CSR-hub verify
 * endpoint (Change 17): it verifies the payment AND advances the order's
 * current payment-verification stage so the next phase unlocks — all from one
 * click on the Dashboard, without opening the order.
 */
class PendingApprovalsService
{
    public function __construct(
        protected OrderPaymentService $payments,
        protected OrderStagesService $stages,
    ) {}

    /**
     * The queue: every payment currently awaiting a verification decision,
     * newest-waiting last (oldest first, so the most overdue floats to top
     * when the UI sorts by wait time). Returns presenter rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queue(): array
    {
        $rows = OrderPayment::query()
            ->where('status', OrderPayment::STATUS_FOR_VERIFICATION)
            ->with(['order.items', 'order.currentStage', 'paymentMethod', 'uploadedBy'])
            ->orderBy('uploaded_at')
            ->get();

        return $rows->map(fn (OrderPayment $p) => $this->present($p))->all();
    }

    /** Badge count for the widget header. */
    public function count(): int
    {
        return OrderPayment::where('status', OrderPayment::STATUS_FOR_VERIFICATION)->count();
    }

    /**
     * The CSR "awaiting payment" list — orders sitting at a payment gate whose
     * payment hasn't been recorded yet (status `waiting`) or was rejected and
     * needs re-recording. Same row shape as queue(); the only differences are
     * the status filter and the action (record vs verify), so the two lists
     * never overlap: waiting/rejected here, for_verification on the Dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function awaitingQueue(): array
    {
        $rows = OrderPayment::query()
            ->whereIn('status', [OrderPayment::STATUS_WAITING, OrderPayment::STATUS_REJECTED])
            ->with(['order.items', 'order.currentStage', 'paymentMethod', 'uploadedBy'])
            ->orderBy('uploaded_at')
            ->get();

        return $rows->map(fn (OrderPayment $p) => $this->present($p))->all();
    }

    /** Badge count for the awaiting-payment list. */
    public function awaitingCount(): int
    {
        return OrderPayment::whereIn('status', [
            OrderPayment::STATUS_WAITING,
            OrderPayment::STATUS_REJECTED,
        ])->count();
    }

    /**
     * Approve a pending payment: verify it (Finance/Superadmin/Admin only —
     * enforced inside OrderPaymentService::verify) and advance the order's
     * active payment-verification gate so the next phase unlocks.
     *
     * @return array{payment: array<string,mixed>, advanced: bool, advanced_to: ?string}
     */
    public function approve(int $paymentId): array
    {
        // Change 11 (gate): an order saved as Incomplete via the superadmin
        // "save anyway" override must have its missing details completed before
        // it can be approved into production. Block here — the payment-
        // verification gate is the single chokepoint that activates the
        // production pipeline, so refusing it keeps the order parked.
        $pending = OrderPayment::with('order')->findOrFail($paymentId);
        if ($pending->order && $pending->order->is_incomplete) {
            throw new BusinessRuleException(
                'This order has missing details and must be completed before it can be approved.',
                'ORDER_INCOMPLETE',
                422,
                ['order' => 'Order is marked incomplete.'],
            );
        }

        $payment = $this->payments->verify($paymentId, OrderPayment::STATUS_VERIFIED);

        $advancedTo = null;
        $order = $payment->order;
        if ($order) {
            $gate = $this->currentGateStage($order);
            if ($gate) {
                $next = $this->stages->markComplete($gate->id, 'Payment verified from Dashboard.');
                $advancedTo = $next?->stage;
            }
        }

        return [
            'payment'     => $this->payments->present($payment),
            'advanced'    => $advancedTo !== null,
            'advanced_to' => $advancedTo,
        ];
    }

    /**
     * Reject a pending payment with a required reason. Leaves the gate stage
     * in place (the order stays parked at the gate until a fresh, valid proof
     * is uploaded and verified).
     *
     * @return array<string, mixed>
     */
    public function reject(int $paymentId, string $reason): array
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => ['A rejection reason is required.'],
            ]);
        }

        $payment = $this->payments->verify($paymentId, OrderPayment::STATUS_REJECTED, $reason);

        return $this->payments->present($payment);
    }

    /**
     * Put the order's active payment-verification gate on hold (e.g. the proof
     * is unclear and the approver wants to pause without rejecting). The proof
     * stays `for_verification`; only the workflow stage is paused.
     */
    public function hold(int $paymentId, ?string $reason = null): array
    {
        $payment = OrderPayment::with('order')->findOrFail($paymentId);
        $heldStage = null;

        if ($payment->order) {
            $gate = $this->currentGateStage($payment->order);
            if ($gate) {
                $stage = $this->stages->markOnHold($gate->id, $reason);
                $heldStage = $stage->stage;
            }
        }

        return [
            'payment_id'  => $payment->id,
            'held'        => $heldStage !== null,
            'held_stage'  => $heldStage,
        ];
    }

    /**
     * The order's currently-active payment-verification stage, if any. We look
     * at active statuses (in_progress / for_approval / delayed) and return the
     * lowest-tier one that is a payment gate.
     */
    private function currentGateStage(Order $order): ?OrderStage
    {
        return OrderStage::where('order_id', $order->id)
            ->whereIn('status', [
                OrderStage::STATUS_IN_PROGRESS,
                OrderStage::STATUS_FOR_APPROVAL,
                OrderStage::STATUS_DELAYED,
            ])
            ->orderBy('sequence')
            ->get()
            ->first(fn (OrderStage $s) => WorkflowStages::isPaymentGate($s->stage));
    }

    /**
     * Presenter row for one pending payment.
     *
     * @return array<string, mixed>
     */
    private function present(OrderPayment $p): array
    {
        $order = $p->order;
        $gate = $order ? $this->currentGateStage($order) : null;

        // Gate label: prefer the live workflow stage; fall back to the payment
        // type so the row is still meaningful if the stage isn't active yet.
        $gateLabel = $gate
            ? (WorkflowStages::find($gate->stage)['label'] ?? $gate->stage)
            : $this->labelForType($p->payment_type);

        $qty = $order && $order->relationLoaded('items')
            ? (int) $order->items->sum('quantity')
            : null;

        $uploadedAt = $p->uploaded_at ? Carbon::parse($p->uploaded_at) : null;

        return [
            'payment_id'       => $p->id,
            'order_id'         => $p->order_id,
            'project_no'       => $order?->po_code,
            'client'           => $order?->client_name,
            'brand'            => $order?->client_brand,
            'qty'              => $qty,
            'rush'             => (bool) ($order?->rush_order ?? false),
            'gate'             => $gateLabel,
            'gate_stage'       => $gate?->stage,
            'payment_type'     => $p->payment_type,
            'amount'           => (float) $p->amount,
            // §2.3 — the verifier must see who paid, through which channel, and
            // WHEN the money was sent (distinct from uploaded_at = when the proof
            // was recorded). Method is the PaymentMethods lookup name (GCash /
            // BPI / etc); paymentMethod is eager-loaded in queue()/awaitingQueue().
            'payer'            => $p->payer_name,
            'method'           => $p->paymentMethod?->name,
            'paid_at'          => $p->paid_at?->toIso8601String(),
            'reference_number' => $p->reference_number,
            'proof_url'        => $p->proof_path ? Storage::disk('public')->url($p->proof_path) : null,
            'uploaded_by'      => $p->uploadedBy?->name,
            'uploaded_at'      => $uploadedAt?->toIso8601String(),
            'waiting_seconds'  => $uploadedAt ? $uploadedAt->diffInSeconds(now()) : null,
        ];
    }

    private function labelForType(?string $type): string
    {
        return match ($type) {
            OrderPayment::TYPE_SAMPLE       => 'Payment Verification (Sample)',
            OrderPayment::TYPE_DOWN_PAYMENT => 'Payment Verification (Mass)',
            OrderPayment::TYPE_BALANCE      => 'Payment Verification (Balance)',
            default                         => 'Payment Verification',
        };
    }
}
