<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderStage;
use App\Support\WorkflowStages;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
     * Auto-create the pending payment for an order's currently-active payment
     * gate so it appears on the Dashboard "Pending Approvals" queue the moment
     * the order reaches the gate — no CSR proof upload required (Finance
     * confirms the expected amount directly).
     *
     * Expected amount is read from the order's stored breakdown_json:
     *   - sample gate  → sample_breakdown total (unit_price x quantity)
     *   - mass gate    → downpayment (Addendum 5.4 — 60%)
     *   - balance gate → balance (40%)
     *
     * Idempotent: at most ONE payment per gate type. If a payment of that type
     * already exists in ANY state (incl. rejected) it is returned untouched, so
     * re-initialization / re-advancing never duplicates or resurrects one.
     *
     * Returns the existing-or-new payment, or null when there is no active
     * payment gate. No-op when the order_payments table is unavailable (narrow
     * test harnesses that hand-build only a partial schema).
     */
    public function ensureGatePayment(Order $order): ?OrderPayment
    {
        if (! Schema::hasTable('order_payments')) {
            return null;
        }

        $gate = $this->activeGateStage($order->id);
        if (! $gate) {
            return null;
        }

        $type = self::paymentTypeForGate($gate->stage, $order->payment_plan ?? null);
        if ($type === null) {
            return null;
        }

        $existing = OrderPayment::where('order_id', $order->id)
            ->where('payment_type', $type)
            ->first();
        if ($existing) {
            return $existing;
        }

        $payment = OrderPayment::create([
            'order_id'            => $order->id,
            'payment_type'        => $type,
            'amount'              => $this->expectedGateAmount($order, $gate->stage),
            'status'              => OrderPayment::STATUS_WAITING,
            'uploaded_at'         => now(),   // anchors the awaiting-payment wait timer
            'uploaded_by_user_id' => null,    // system-created at the gate
            'notes'               => 'Auto-created when the order reached this payment gate. Awaiting CSR to record the client payment.',
        ]);

        $this->logger->log(
            action: 'payment_gate.auto_created',
            summary: "{$payment->payment_type} \u{20B1}" . number_format((float) $payment->amount, 2) . ' (waiting)',
            subject: $payment,
            orderId: $order->id,
            clientId: $order->client_id,
            data: [
                'payment_type' => $payment->payment_type,
                'amount'       => (float) $payment->amount,
                'gate_stage'   => $gate->stage,
            ],
        );

        return $payment->fresh();
    }

    /**
     * The order's currently-active payment-verification gate (in_progress /
     * for_approval / delayed), lowest-tier first, or null.
     */
    private function activeGateStage(int $orderId): ?OrderStage
    {
        return OrderStage::where('order_id', $orderId)
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
     * Map a payment-gate stage slug to its OrderPayment type.
     *
     * Full-Payment plan: the sample gate (seq 4, the workflow's first stage)
     * collects the ENTIRE grand total upfront, so its payment is typed `full`
     * instead of `sample`. The mass/balance gates keep their types — for a
     * full-plan order those rows are the ₱0 auto-passed paper-trail entries.
     */
    private static function paymentTypeForGate(string $stage, ?string $plan = null): ?string
    {
        return match ($stage) {
            'payment_verification_sample'  => $plan === 'full_payment'
                ? OrderPayment::TYPE_FULL
                : OrderPayment::TYPE_SAMPLE,
            'payment_verification_mass'    => OrderPayment::TYPE_DOWN_PAYMENT,
            'payment_verification_balance' => OrderPayment::TYPE_BALANCE,
            default                        => null,
        };
    }

    /**
     * The payment type the order currently owes at its active gate (sample /
     * down_payment / balance), or null if it isn't sitting at a payment gate.
     * Lets the Enter-Payment form default the type instead of asking the user.
     */
    public function activeGatePaymentType(Order $order): ?string
    {
        $gate = $this->activeGateStage($order->id);

        return $gate ? self::paymentTypeForGate($gate->stage, $order->payment_plan ?? null) : null;
    }

    /**
     * Full-Payment auto-pass paper trail — settle the given gate's payment as
     * a ₱0 VERIFIED row so the ledger records that the gate was reached and
     * owed nothing (the client paid the full grand total upfront).
     *
     * Reconciles with any existing non-verified row of the gate's type (e.g.
     * a `waiting` stub from an earlier ensureGatePayment) instead of inserting
     * a duplicate. An already-verified row is terminal and returned untouched.
     * Idempotent; no-op when the order_payments table is unavailable.
     */
    public function settleGateAsAutoPassed(Order $order, OrderStage $gate): ?OrderPayment
    {
        if (! Schema::hasTable('order_payments')) {
            return null;
        }

        $type = self::paymentTypeForGate($gate->stage, $order->payment_plan ?? null);
        if ($type === null) {
            return null;
        }

        $note = 'Auto-passed — Full Payment plan; the order was paid in full upfront, so this gate owed ₱0.';

        $payment = OrderPayment::where('order_id', $order->id)
            ->where('payment_type', $type)
            ->latest('id')
            ->first();

        if ($payment && $payment->status === OrderPayment::STATUS_VERIFIED) {
            return $payment;
        }

        if ($payment) {
            $payment->update([
                'amount'              => 0,
                'status'              => OrderPayment::STATUS_VERIFIED,
                'verified_by_user_id' => Auth::id(),
                'verified_at'         => now(),
                'rejection_reason'    => null,
                'notes'               => $note,
            ]);
        } else {
            $payment = OrderPayment::create([
                'order_id'            => $order->id,
                'payment_type'        => $type,
                'amount'              => 0,
                'status'              => OrderPayment::STATUS_VERIFIED,
                'uploaded_at'         => now(),
                'uploaded_by_user_id' => null,
                'verified_by_user_id' => Auth::id(),
                'verified_at'         => now(),
                'notes'               => $note,
            ]);
        }

        $this->logger->log(
            action: 'payment_gate.auto_passed',
            summary: "{$payment->payment_type} \u{20B1}0.00 (verified — Full Payment plan)",
            subject: $payment,
            orderId: $order->id,
            clientId: $order->client_id,
            data: [
                'payment_type' => $payment->payment_type,
                'amount'       => 0.0,
                'gate_stage'   => $gate->stage,
                'payment_plan' => 'full_payment',
            ],
        );

        return $payment->fresh();
    }

    /**
     * Expected amount for a gate, from the order's stored breakdown_json.
     * NOTE: the sample-fee source is the one piece worth confirming against the
     * authoritative pricing rules — adjust sampleGateAmount() if it should be a
     * fixed sample fee rather than the computed sample_breakdown total.
     */
    private function expectedGateAmount(Order $order, string $stage): float
    {
        $b = is_array($order->breakdown_json) ? $order->breakdown_json : [];

        // Full-Payment plan: every gate expects whatever is still OWED —
        // grand_total minus the verified payments so far. On a fresh order
        // that is the entire grand total at the sample gate and ₱0 at the
        // later gates (which auto-pass). On a legacy full-plan order that
        // already paid only the sample fee, the mass gate correctly bills
        // the remaining amount; once that is verified, the balance gate
        // auto-passes.
        if (($order->payment_plan ?? null) === 'full_payment') {
            $verified = (float) OrderPayment::where('order_id', $order->id)
                ->where('status', OrderPayment::STATUS_VERIFIED)
                ->sum('amount');

            return max(0.0, round((float) $order->grand_total - $verified, 2));
        }

        return match ($stage) {
            'payment_verification_mass'    => round((float) ($b['downpayment'] ?? 0), 2),
            'payment_verification_balance' => round((float) ($b['balance'] ?? 0), 2),
            'payment_verification_sample'  => $this->sampleGateAmount($b),
            default                        => 0.0,
        };
    }

    /** Sample-fee amount = sample_breakdown unit_price x quantity (0 if no sample). */
    private function sampleGateAmount(array $breakdown): float
    {
        $s = is_array($breakdown['sample_breakdown'] ?? null) ? $breakdown['sample_breakdown'] : [];
        $unit = (float) ($s['unit_price'] ?? 0);
        $qty  = (float) ($s['quantity'] ?? 0);

        return round($unit * $qty, 2);
    }

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

            // Reconcile with the gate's auto-created stub (ensureGatePayment) or
            // an earlier waiting/rejected attempt for this same payment_type, so
            // we update that single row instead of inserting a parallel one.
            // Without this, the stub (no proof) and this upload (with proof) both
            // sit in the Dashboard "Pending Approvals" queue as duplicates.
            // A VERIFIED payment is terminal and is never touched here.
            $payment = OrderPayment::where('order_id', $order->id)
                ->where('payment_type', $data['payment_type'])
                ->where('status', '!=', OrderPayment::STATUS_VERIFIED)
                ->latest('id')
                ->first();

            if ($payment) {
                $payment->amount            = $data['amount'];
                $payment->payment_method_id = $data['payment_method_id'] ?? $payment->payment_method_id;
                $payment->reference_number  = $data['reference_number']  ?? $payment->reference_number;
                $payment->notes             = $data['notes'] ?? $payment->notes;
                $payment->payer_name        = $data['payer_name'] ?? $payment->payer_name;
                $payment->paid_at           = $data['paid_at']    ?? $payment->paid_at;

                // Only (re)attach proof and advance to for_verification when an
                // actual file arrived; a metadata-only call must not wipe an
                // existing proof or downgrade the row's status.
                if ($proof !== null) {
                    $payment->proof_path          = $proofPath;
                    $payment->status              = OrderPayment::STATUS_FOR_VERIFICATION;
                    $payment->uploaded_at         = $uploadedAt;
                    $payment->uploaded_by_user_id = Auth::id();
                }

                $payment->save();
            } else {
                $payment = OrderPayment::create([
                    'order_id'            => $order->id,
                    'payment_type'        => $data['payment_type'],
                    'amount'              => $data['amount'],
                    'payment_method_id'   => $data['payment_method_id'] ?? null,
                    'reference_number'    => $data['reference_number']  ?? null,
                    'payer_name'          => $data['payer_name'] ?? null,
                    'paid_at'             => $data['paid_at']    ?? null,
                    'proof_path'          => $proofPath,
                    'status'              => $status,
                    'uploaded_by_user_id' => $proof !== null ? Auth::id() : null,
                    'uploaded_at'         => $uploadedAt,
                    'notes'               => $data['notes'] ?? null,
                ]);
            }

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
     * Review-Hub payment map — every payment-verification gate stage of the
     * order (whatever its status) keyed by order_stage_id, each carrying the
     * FULL payment record: amount, payer, method name, reference, proof URL,
     * who recorded it, who verified it and when, plus status/notes.
     *
     * This is the permanent home of a verified payment's details: once
     * Finance approves, the row leaves the Dashboard "Pending Approvals"
     * queue, so the Review Hub card is where staff re-open it later.
     *
     * The sample gate accepts BOTH `full` (Full-Payment plan) and `sample`
     * typed rows — a legacy full-plan order whose payment was recorded as
     * `sample` before the Full-Payment rule shipped still resolves.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forReviewHub(Order $order): array
    {
        if (! Schema::hasTable('order_payments')) {
            return [];
        }

        $gates = OrderStage::where('order_id', $order->id)
            ->orderBy('sequence')
            ->get()
            ->filter(fn (OrderStage $s) => WorkflowStages::isPaymentGate($s->stage));

        if ($gates->isEmpty()) {
            return [];
        }

        $payments = OrderPayment::with(['paymentMethod', 'uploadedBy:id,name', 'verifiedBy:id,name'])
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->get();

        $map = [];
        foreach ($gates as $gate) {
            $primary = self::paymentTypeForGate($gate->stage, $order->payment_plan ?? null);
            if ($primary === null) {
                continue;
            }

            $candidates = $gate->stage === 'payment_verification_sample'
                ? array_values(array_unique([$primary, OrderPayment::TYPE_FULL, OrderPayment::TYPE_SAMPLE]))
                : [$primary];

            $payment = null;
            foreach ($candidates as $type) {
                $payment = $payments->firstWhere('payment_type', $type);
                if ($payment) {
                    break;
                }
            }
            if (! $payment) {
                continue;
            }

            $map[$gate->id] = [
                'id'               => $payment->id,
                'payment_type'     => $payment->payment_type,
                'amount'           => (float) $payment->amount,
                'status'           => $payment->status,
                'payer_name'       => $payment->payer_name,
                'paid_at'          => optional($payment->paid_at)->toIso8601String(),
                'method_name'      => $payment->paymentMethod?->name,
                'reference_number' => $payment->reference_number,
                'proof_url'        => $payment->proof_path
                    ? Storage::disk('public')->url($payment->proof_path)
                    : null,
                'uploaded_by_name' => $payment->uploadedBy?->name,
                'uploaded_at'      => optional($payment->uploaded_at)->toIso8601String(),
                'verified_by_name' => $payment->verifiedBy?->name,
                'verified_at'      => optional($payment->verified_at)->toIso8601String(),
                'rejection_reason' => $payment->rejection_reason,
                'notes'            => $payment->notes,
            ];
        }

        return $map;
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
