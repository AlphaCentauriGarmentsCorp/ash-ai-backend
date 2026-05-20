<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QaPacker\StoreReject;
use App\Services\QaPackerPortalService;
use App\Services\QaPackerSubmitService;
use App\Services\RejectLogService;
use Illuminate\Http\Request;

/**
 * Phase 7-B Bundle 1 — HTTP layer for the QA/Packer portal.
 *
 * Endpoints (all gated by permission:portal.qa-packer at route layer):
 *
 *   GET    /api/v2/portal/qa-packer/context/{orderStageId}
 *   POST   /api/v2/portal/qa-packer/rejects            (multipart or JSON)
 *   DELETE /api/v2/portal/qa-packer/rejects/{id}
 *   POST   /api/v2/portal/qa-packer/submit/{orderStageId}
 *
 * The /context endpoint is the page-render workhorse — Bundle 2 builds
 * its frontend.
 *
 * The /rejects endpoints handle BOTH reject and repair entries; the
 * `disposition` field on the body disambiguates.
 *
 * The /submit endpoint is the atomic "Submit Completed" action. In
 * Bundle 1 the side effects (inventory decrement, full notification
 * fan-out, checklist persistence) are stubbed — see QaPackerSubmitService
 * for the TODO Bundle 4 markers.
 */
class QaPackerPortalController extends Controller
{
    public function __construct(
        protected QaPackerPortalService $context,
        protected RejectLogService $rejects,
        protected QaPackerSubmitService $submitter,
    ) {
    }

    /**
     * GET /portal/qa-packer/context/{orderStageId}
     */
    public function showContext(int $orderStageId)
    {
        $payload = $this->context->buildContext($orderStageId);
        return response()->json(['data' => $payload]);
    }

    /**
     * POST /portal/qa-packer/rejects
     *
     * Accepts JSON or multipart. If a `photo` file is present, it's
     * stored on the public disk under `qa-packer/reject-photos/` and
     * its path becomes the stored `photo_path`.
     */
    public function storeReject(StoreReject $request)
    {
        $data = $request->validated();

        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            $data['photo_path'] = $request->file('photo')
                ->store('qa-packer/reject-photos', 'public');
        }
        unset($data['photo']);

        $log = $this->rejects->create($data, $request->user());
        $log->load(['reason:id,slug,label', 'loggedBy:id,name']);

        return response()->json([
            'data' => [
                'id'             => $log->id,
                'order_id'       => $log->order_id,
                'order_stage_id' => $log->order_stage_id,
                'disposition'    => $log->disposition,
                'quantity_pcs'   => $log->quantity_pcs,
                'reason'         => $log->reason ? [
                    'id'    => $log->reason->id,
                    'slug'  => $log->reason->slug,
                    'label' => $log->reason->label,
                ] : null,
                'photo_path'     => $log->photo_path,
                'notes'          => $log->notes,
                'logged_by'      => $log->loggedBy?->name,
                'created_at'     => $log->created_at?->toDateTimeString(),
            ],
        ], 201);
    }

    /**
     * DELETE /portal/qa-packer/rejects/{id}
     */
    public function destroyReject(int $id, Request $request)
    {
        $this->rejects->delete($id, $request->user());
        return response()->json(['message' => 'Reject/repair entry deleted'], 200);
    }

    /**
     * POST /portal/qa-packer/submit/{orderStageId}
     *
     * The big atomic SUBMIT COMPLETED. Body shape (all optional in
     * Bundle 1, validated in service):
     *
     *   {
     *     "qa_checklist_state":      { "correct_print": true, ... },
     *     "packing_checklist_state": { "fold_and_pack": true, ... },
     *     "final_photos":            { "packed_items": "...", "box": "...", "qr_label": "..." },
     *     "notes":                   "optional submit-time note"
     *   }
     */
    public function submit(int $orderStageId, Request $request)
    {
        $validated = $request->validate([
            'qa_checklist_state'         => 'nullable|array',
            'packing_checklist_state'    => 'nullable|array',
            'final_photos'               => 'nullable|array',
            'notes'                      => 'nullable|string|max:1000',
        ]);

        $result = $this->submitter->submit(
            $orderStageId,
            $validated,
            $request->user(),
        );

        return response()->json(['data' => $result], 200);
    }
}
