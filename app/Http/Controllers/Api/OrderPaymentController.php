<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Csr\UploadPayment;
use App\Http\Requests\Csr\VerifyPayment;
use App\Services\OrderPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderPaymentController extends Controller
{
    public function __construct(
        protected OrderPaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $list = $this->payments->list([
            'order_id' => $request->integer('order_id') ?: null,
            'status'   => $request->string('status')->value() ?: null,
        ]);

        return response()->json([
            'data' => $list->map(fn ($p) => $this->payments->present($p))->all(),
        ]);
    }

    /**
     * POST /csr/payments — upload payment proof.
     *
     * Multipart. The 'proof' file is optional. When present, status
     * is set to 'for_verification'; otherwise status='waiting'.
     */
    public function store(UploadPayment $request): JsonResponse
    {
        $data = $request->validated();

        $payment = $this->payments->uploadProof(
            $data['order_id'],
            $data,
            $request->file('proof'),
        );

        return response()->json([
            'data' => $this->payments->present($payment),
        ], 201);
    }

    /**
     * PATCH /csr/payments/{id}/verify — Finance verifies a payment.
     *
     * Route is gated on `action.verify-payment`. CSR cannot reach this.
     * The service double-checks the permission too.
     */
    public function verify(VerifyPayment $request, int $id): JsonResponse
    {
        $data = $request->validated();

        $payment = $this->payments->verify(
            $id,
            $data['decision'],
            $data['rejection_reason'] ?? null,
        );

        return response()->json([
            'data' => $this->payments->present($payment),
        ]);
    }
}
