<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Csr\DecideSampleApproval;
use App\Services\SampleApprovalService;
use Illuminate\Http\JsonResponse;

/**
 * CSR sample-approval surface (Phase 3).
 *
 * Mounted under the portal.csr-gated /csr group. Verification of the client's
 * sample verdict is a CSR responsibility (distinct from the Finance-only
 * payment-gate approval), so CSR holds both endpoints here.
 */
class SampleApprovalController extends Controller
{
    public function __construct(
        protected SampleApprovalService $samples,
    ) {}

    /**
     * GET /csr/sample-approvals — orders awaiting CSR's sample decision.
     * Drives the "Samples for Approval" worklist + its badge.
     */
    public function awaiting(): JsonResponse
    {
        return response()->json([
            'data'  => $this->samples->awaitingQueue(),
            'count' => $this->samples->awaitingCount(),
        ]);
    }

    /**
     * POST /csr/sample-approvals — record the client's verdict on a sample.
     *
     * approve → advances to Payment Verification (Mass);
     * reject  → loops the sample sub-flow back to Graphic Artwork.
     */
    public function store(DecideSampleApproval $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->samples->decide(
            $data['order_id'],
            $data['decision'],
            $data,
            $request->file('screenshot'),
        );

        return response()->json([
            'message'    => $result['outcome'] === 'advanced'
                ? 'Sample approved. Order advanced to mass-production payment.'
                : 'Sample rejected. Order looped back to Graphic Artwork.',
            'outcome'    => $result['outcome'],
            'next_stage' => $result['next_stage'],
        ], 201);
    }
}
