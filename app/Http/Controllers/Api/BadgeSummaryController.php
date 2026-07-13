<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PendingApprovalsService;
use App\Services\PortalAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BadgeSummaryController — CP-3.
 *
 * One call for every count the sidebar and dashboard header render, so the
 * frontend BadgeProvider stops firing a separate request per badge on each
 * poll. This consolidates what used to be two round-trips — /portal/badge-counts
 * and /csr/payments/awaiting — plus the Dashboard pending-approvals count.
 *
 * The endpoint is auth-only; each field is SELF-SCOPED to mirror exactly who may
 * open the corresponding list, so a user never learns a count they aren't
 * allowed to act on. Fields are OMITTED (not zeroed) when the user lacks the
 * gate, so the client can treat a missing key as "no badge":
 *
 *   - portals            always present. PortalAssignmentService::badgeCounts()
 *                        already filters to the user's own stations (oversight
 *                        roles see every station).
 *   - awaiting           only when the user can open the CSR awaiting-payment
 *                        list — permission portal.csr, the same gate as
 *                        GET /csr/payments/awaiting.
 *   - pending_approvals  only for payment approvers — permission
 *                        action.verify-payment, the same gate as the Dashboard
 *                        pending-approvals queue.
 *
 * The two payment counts reuse PendingApprovalsService's existing queries, so
 * the RC-5 soft-deleted-order guard (whereHas('order')) applies here for free.
 */
class BadgeSummaryController extends Controller
{
    public function __construct(
        protected PortalAssignmentService $portals,
        protected PendingApprovalsService $approvals,
    ) {}

    /** GET /api/v2/badges — every sidebar/dashboard count in one payload. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $out = [
            // Self-scoped inside the service: oversight roles get every
            // station's total; everyone else gets only their own queue counts.
            'portals' => $this->portals->badgeCounts($user),
        ];

        // Mirror the gate on GET /csr/payments/awaiting.
        if ($user->can('portal.csr')) {
            $out['awaiting'] = $this->approvals->awaitingCount();
        }

        // Mirror the gate on the Dashboard pending-approvals queue.
        if ($user->can('action.verify-payment')) {
            $out['pending_approvals'] = $this->approvals->count();
        }

        return response()->json($out);
    }
}
