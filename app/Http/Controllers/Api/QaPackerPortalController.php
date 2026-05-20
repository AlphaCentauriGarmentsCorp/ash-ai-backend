<?php

namespace App\Http\Controllers\Api;
  
use App\Http\Controllers\Controller;
use App\Http\Requests\QaPacker\StoreFinalPhoto;
use App\Http\Requests\QaPacker\StoreReject;
use App\Http\Requests\QaPacker\UpdateBoxContents;
use App\Models\OrderPackingBox;
use App\Services\BoxQrCodeService;
use App\Services\QaPackerPortalService;
use App\Services\QaPackerSubmitService;
use App\Services\RejectLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        protected BoxQrCodeService $boxes,
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

    // ─── Bundle 4a additions ────────────────────────────────────────

    /**
     * POST /portal/qa-packer/boxes/ensure-for-order/{orderId}
     *
     * Idempotent: returns the existing box #1 if any box exists for
     * the order, otherwise auto-creates one with defaults derived from
     * items_json. Called by the frontend when the packing section
     * mounts.
     */
    public function ensureFirstBox(int $orderId, Request $request)
    {
        $box = $this->boxes->ensureFirstBox($orderId, $request->user());

        return response()->json([
            'data' => $this->boxToArray($box),
        ], 200);
    }

    /**
     * PATCH /portal/qa-packer/boxes/{id}
     *
     * Update box contents + optional weight. Refuses if the box is
     * already sealed (sealing locks the contents).
     */
    public function updateBoxContents(int $id, UpdateBoxContents $request)
    {
        $box = OrderPackingBox::findOrFail($id);

        if ($box->isSealed()) {
            throw ValidationException::withMessages([
                'id' => 'Box is sealed — contents cannot be edited.',
            ]);
        }

        $data = $request->validated();
        $box->update([
            'contents_json' => $data['contents_json'],
            'weight_kg'     => $data['weight_kg'] ?? $box->weight_kg,
        ]);

        return response()->json([
            'data' => $this->boxToArray($box->fresh()),
        ], 200);
    }

    /**
     * POST /portal/qa-packer/boxes/{id}/seal
     *
     * Seal the box and mark it ready for QR-label print.
     */
    public function sealBox(int $id, Request $request)
    {
        $box = $this->boxes->seal($id, $request->user());

        return response()->json([
            'data' => $this->boxToArray($box),
        ], 200);
    }

    /**
     * POST /portal/qa-packer/boxes/{id}/unseal
     *
     * Bundle 4a-2 — packer self-service unseal (before submit only).
     */
    public function unsealBox(int $id, Request $request)
    {
        $box = $this->boxes->unseal($id, $request->user());

        return response()->json([
            'data' => $this->boxToArray($box),
        ], 200);
    }

    /**
     * GET /portal/qa-packer/boxes/{id}/qr-label.pdf
     *
     * Stream the QR label PDF. Browser opens in a new tab → user prints.
     */
    public function downloadBoxLabel(int $id)
    {
        $box = OrderPackingBox::with('order')->findOrFail($id);
        $pdfBytes = $this->boxes->renderLabelPdf($box);

        return response($pdfBytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"box-{$box->qr_code}.pdf\"",
        ]);
    }

    /**
     * POST /portal/qa-packer/final-photos
     *
     * Multipart. Saves one of the three final-photo types and returns
     * the stored path so the frontend can keep it in client state
     * until the SUBMIT button is hit.
     *
     * Bundle 4a stores the photo on the public disk and returns its
     * relative path; the frontend is responsible for sending the full
     * { kind: path } map back as `final_photos` on the /submit call.
     */
    public function uploadFinalPhoto(StoreFinalPhoto $request)
    {
        $data = $request->validated();

        $path = $request->file('photo')->store(
            'qa-packer/final-photos',
            'public',
        );

        return response()->json([
            'data' => [
                'kind' => $data['kind'],
                'path' => $path,
                'order_id'       => $data['order_id'],
                'order_stage_id' => $data['order_stage_id'],
            ],
        ], 201);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Format a box for API responses. Keeps the controller's box
     * payload shape identical to QaPackerPortalService::packingBoxes
     * so the frontend can blindly merge box updates back into its
     * local copy of context.packing_boxes.
     */
    protected function boxToArray(OrderPackingBox $box): array
    {
        return [
            'id'             => $box->id,
            'box_number'     => $box->box_number,
            'qr_code'        => $box->qr_code,
            'contents_json'  => $box->contents_json,
            'total_pieces'   => $box->totalPieces(),
            'weight_kg'      => $box->weight_kg !== null ? (float) $box->weight_kg : null,
            'sealed_at'      => $box->sealed_at?->toDateTimeString(),
            'is_sealed'      => $box->isSealed(),
        ];
    }
}
