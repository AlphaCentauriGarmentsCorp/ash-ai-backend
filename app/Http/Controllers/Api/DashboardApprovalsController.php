<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PendingApprovalsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * DashboardApprovalsController — the Dashboard "Pending Approvals" queue
 * (ASH AI Change Request 2026-06-02, Change 1B).
 *
 * Surfaces every order awaiting a Payment Verification gate and lets an
 * approver Approve / Reject / Hold in one click WITHOUT opening the order.
 *
 * The whole controller is route-gated by `action.verify-payment`
 * (Finance / Superadmin / Admin), which is also the Change 17 integrity
 * control — CSR can upload proof but never reaches these actions.
 */
class DashboardApprovalsController extends Controller
{
    public function __construct(protected PendingApprovalsService $approvals) {}

    /** GET /v2/dashboard/pending-approvals — queue + badge count. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data'  => $this->approvals->queue(),
            'count' => $this->approvals->count(),
        ]);
    }

    /** POST /v2/dashboard/pending-approvals/{payment}/approve */
    public function approve(int $payment): JsonResponse
    {
        try {
            $result = $this->approvals->approve($payment);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Cannot approve payment.',
                'errors'  => $e->errors(),
            ], $e->status ?: 422);
        }

        return response()->json([
            'message' => 'Payment approved.',
            'data'    => $result,
        ]);
    }

    /** POST /v2/dashboard/pending-approvals/{payment}/reject */
    public function reject(Request $request, int $payment): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        try {
            $result = $this->approvals->reject($payment, $data['reason']);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Cannot reject payment.',
                'errors'  => $e->errors(),
            ], $e->status ?: 422);
        }

        return response()->json([
            'message' => 'Payment rejected.',
            'data'    => $result,
        ]);
    }

    /** POST /v2/dashboard/pending-approvals/{payment}/hold */
    public function hold(Request $request, int $payment): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $result = $this->approvals->hold($payment, $data['reason'] ?? null);

        return response()->json([
            'message' => 'Payment gate placed on hold.',
            'data'    => $result,
        ]);
    }
}
