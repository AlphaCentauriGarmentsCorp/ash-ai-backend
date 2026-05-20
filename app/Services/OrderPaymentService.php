<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * OrderPaymentService — per-payment lifecycle + Finance verification gate.
 *
 * State machine:
 *
 *   waiting  ─[uploadProof]─→  for_verification
 *                                    │
 *                          (action.verify-payment)
 *                                    │
 *                            ┌───────┴───────┐
 *                       verify(approve)   verify(reject)
 *                                │               │
 *                            verified        rejected
 *
 * Permission gates:
 *   - CSR (portal.csr)             can: uploadProof
 *   - Finance/SuperAdmin (action.verify-payment) can: verify
 *
 * The verification gate lives in this service, not the controller —
 * keeps the permission check next to the state transition.
 */
class OrderPaymentService
{
    public function __construct(
        protected CsrActivityLogger $logger,
    ) {}

    /**
     * List payments with optional filters.
     *
     * @param array{order_id?: int, status?: string} $filters
     */
    public function list(array $filters = []): Collection
    {
        $q = OrderPayment::with(['order', 'paymentMethod', 'uploadedBy', 'verifiedBy']);

        if (!empty($filters['order_id'])) {
            $q->where('order_id', $filters['order_id']);
        }
        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return $q->orderByDesc('created_at')->get();
    }

    public function find(int $id): ?OrderPayment
    {
        return OrderPayment::with(['order', 'paymentMethod', 'uploadedBy', 'verifiedBy'])->find($id);
    }

    /**
     * CSR or client uploads a payment proof.
     *
     * Creates the OrderPayment row in `for_verification` status (since
     * the upload is the act of submitting for review). If no proof
     * file is given, status stays `waiting`.
     */
    public function uploadProof(int $orderId, array $data, ?UploadedFile $proof = null): OrderPayment
    {
        return DB::transaction(function () use ($orderId, $data, $proof) {
            /** @var Order $order */
            $order = Order::lockForUpdate()->findOrFail($orderId);

            $proofPath = null;
            $status    = OrderPayment::STATUS_WAITING;
            $uploadedAt = null;

            if ($proof !== null) {
                $proofPath = $proof->store("csr/payments/{$order->id}", 'public');
                $status     = OrderPayment::STATUS_FOR_VERIFICATION;
                $uploadedAt = now();
            }

            $payment = OrderPayment::create([
                'order_id'            => $order->id,
                'payment_type'        => $data['payment_type'],
                'amount'              => $data['amount'],
                'payment_method_id'   => $data['payment_method_id'] ?? null,
                'reference_number'    => $data['reference_number']  ?? null,
                'proof_path'          => $proofPath,
                'status'              => $status,
                'uploaded_by_user_id' => $proof !== null ? Auth::id() : null,
                'uploaded_at'         => $uploadedAt,
                'notes'               => $data['notes'] ?? null,
            ]);

            $this->logger->log(
                action: 'payment_proof.uploaded',
                summary: "{$payment->payment_type} ₱" . number_format((float) $payment->amount, 2)
                    . " ({$payment->status})",
                subject: $payment,
                orderId: $order->id,
                clientId: $order->client_id,
                data: [
                    'payment_type' => $payment->payment_type,
                    'amount'       => (float) $payment->amount,
                    'to_status'    => $payment->status,
                ],
            );

            return $payment->fresh(['order', 'paymentMethod', 'uploadedBy', 'verifiedBy']);
        });
    }

    /**
     * Finance verifies a payment (approve or reject).
     *
     * Gated on `action.verify-payment` — CSR cannot reach this method
     * because the route group is permission-gated, but as defense in
     * depth we re-check here.
     *
     * @param string      $decision 'verified' | 'rejected'
     * @param string|null $rejectionReason  required when decision === 'rejected'
     *
     * @throws ValidationException 403 if the caller lacks action.verify-payment
     * @throws ValidationException 422 on invalid state transition
     */
    public function verify(int $paymentId, string $decision, ?string $rejectionReason = null): OrderPayment
    {
        $user = Auth::user();
        if (!$user || !$user->can('action.verify-payment')) {
            throw ValidationException::withMessages([
                'permission' => ['You are not allowed to verify payments.'],
            ])->status(403);
        }

        if (!in_array($decision, [OrderPayment::STATUS_VERIFIED, OrderPayment::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'decision' => ['Decision must be either verified or rejected.'],
            ]);
        }

        if ($decision === OrderPayment::STATUS_REJECTED && empty($rejectionReason)) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['A rejection reason is required when rejecting a payment.'],
            ]);
        }

        return DB::transaction(function () use ($paymentId, $decision, $rejectionReason) {
            /** @var OrderPayment $payment */
            $payment = OrderPayment::lockForUpdate()->findOrFail($paymentId);

            // State machine guard — can only verify from for_verification
            if ($payment->status !== OrderPayment::STATUS_FOR_VERIFICATION) {
                throw ValidationException::withMessages([
                    'status' => [
                        "Payment is in status '{$payment->status}'. Only 'for_verification' rows can be verified.",
                    ],
                ]);
            }

            $fromStatus = $payment->status;

            $payment->update([
                'status'              => $decision,
                'verified_by_user_id' => Auth::id(),
                'verified_at'         => now(),
                'rejection_reason'    => $decision === OrderPayment::STATUS_REJECTED
                    ? $rejectionReason
                    : null,
            ]);

            $this->logger->log(
                action: $decision === OrderPayment::STATUS_VERIFIED ? 'payment.verified' : 'payment.rejected',
                summary: "Payment #{$payment->id} → {$decision}",
                subject: $payment,
                orderId: $payment->order_id,
                clientId: optional($payment->order)->client_id,
                data: [
                    'from_status' => $fromStatus,
                    'to_status'   => $decision,
                    'reason'      => $rejectionReason,
                ],
            );

            return $payment->fresh(['order', 'paymentMethod', 'uploadedBy', 'verifiedBy']);
        });
    }

    /**
     * Build the presenter shape for a payment — includes the public
     * proof URL. Used by the controller's JSON responses.
     */
    public function present(OrderPayment $payment): array
    {
        return [
            'id'                  => $payment->id,
            'order_id'            => $payment->order_id,
            'payment_type'        => $payment->payment_type,
            'amount'              => (float) $payment->amount,
            'payment_method_id'   => $payment->payment_method_id,
            'reference_number'    => $payment->reference_number,
            'proof_path'          => $payment->proof_path,
            'proof_url'           => $payment->proof_path
                ? Storage::disk('public')->url($payment->proof_path)
                : null,
            'status'              => $payment->status,
            'uploaded_by_user_id' => $payment->uploaded_by_user_id,
            'uploaded_at'         => optional($payment->uploaded_at)->toIso8601String(),
            'verified_by_user_id' => $payment->verified_by_user_id,
            'verified_at'         => optional($payment->verified_at)->toIso8601String(),
            'rejection_reason'    => $payment->rejection_reason,
            'notes'               => $payment->notes,
            'created_at'          => $payment->created_at?->toIso8601String(),
        ];
    }
}
