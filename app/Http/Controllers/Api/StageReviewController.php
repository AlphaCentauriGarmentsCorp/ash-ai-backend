<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StageReview\ApproveStageReview;
use App\Http\Requests\StageReview\RejectStageReview;
use App\Http\Requests\StageReview\ResubmitStageReview;
use App\Models\Order;
use App\Models\OrderStage;
use App\Services\StageReviewService;
use Illuminate\Http\Request;

/**
 * CSR Review Hub — HTTP layer.
 *
 * Route-level permission gating (see routes/api.php):
 *   - approve / reject : permission:access.production-review  (csr/super_admin/admin)
 *   - resubmit         : permission:action.advance-stage      (owning production roles)
 *   - read endpoints   : permission:access.orders             (anyone who can see the order)
 *
 * The controller owns image-upload handling (same convention as
 * StageInputsController: store on the 'public' disk under stage-reviews/);
 * StageReviewService owns the state logic and notifications.
 */
class StageReviewController extends Controller
{
    public function __construct(
        protected StageReviewService $service,
        protected \App\Services\StageArtifactService $artifacts,
    ) {
    }

    /**
     * GET /api/v2/orders/{orderId}/stage-reviews
     *
     * The hub's payload: every review row for the order grouped by stage id, a
     * per-stage current-state map (drives approve/reject controls + rejection
     * banners), and the per-stage artifacts — aggregated from ALL sources
     * (design files, sample photos, QA final photos, and generic proof-of-work
     * uploads) so the reviewer sees whatever output the stage actually produced.
     */
    public function indexForOrder(Request $request, int $orderId)
    {
        $order = Order::find($orderId);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $history = $this->service->historyForOrder($orderId);

        // Per-stage current review state, keyed by order_stage_id.
        $states = OrderStage::where('order_id', $orderId)
            ->orderBy('sequence')
            ->get(['id'])
            ->mapWithKeys(fn ($s) => [$s->id => $this->service->stateFor($s->id, $request->user())]);

        // Per-stage artifacts from every source, keyed by order_stage_id.
        $uploads = $this->artifacts->forOrder($orderId);

        return response()->json([
            'order_id' => $orderId,
            'history'  => $history,
            'states'   => $states,
            'uploads'  => $uploads,
        ]);
    }

    /**
     * POST /api/v2/order-stages/{id}/review/approve
     */
    public function approve(ApproveStageReview $request, int $id)
    {
        $review = $this->service->approve(
            $id,
            $request->user(),
            $request->validated()['comment'] ?? null,
        );

        return response()->json([
            'message' => 'Stage approved.',
            'review'  => $this->service->summarize($review->load('actor:id,name')),
        ], 201);
    }

    /**
     * POST /api/v2/order-stages/{id}/review/reject  (multipart/form-data)
     */
    public function reject(RejectStageReview $request, int $id)
    {
        $imagePath = null;
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $imagePath = $request->file('image')->store('stage-reviews', 'public');
        }

        $review = $this->service->reject(
            $id,
            $request->user(),
            $request->validated()['comment'],
            $imagePath,
        );

        return response()->json([
            'message' => 'Stage rejected. The owning role has been notified.',
            'review'  => $this->service->summarize($review->load('actor:id,name')),
        ], 201);
    }

    /**
     * POST /api/v2/order-stages/{id}/review/resubmit
     *
     * Called from the owning role's portal after they fix the rejected work.
     */
    public function resubmit(ResubmitStageReview $request, int $id)
    {
        $review = $this->service->resubmit(
            $id,
            $request->user(),
            $request->validated()['comment'] ?? null,
        );

        return response()->json([
            'message' => 'Resubmitted for review.',
            'review'  => $this->service->summarize($review->load('actor:id,name')),
        ], 201);
    }

    /**
     * GET /api/v2/order-stages/{id}/review/state
     *
     * Lightweight single-stage state probe, used by a production portal to
     * decide whether to show the rejection banner + resubmit action.
     */
    public function state(Request $request, int $id)
    {
        OrderStage::findOrFail($id);

        return response()->json($this->service->stateFor($id, $request->user()));
    }
}