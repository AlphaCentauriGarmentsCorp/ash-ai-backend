<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StageInputs\StoreStageRejectLog;
use App\Http\Requests\StageInputs\StoreStageWasteLog;
use App\Http\Resources\StageRejectLogResource;
use App\Http\Resources\StageWasteLogResource;
use App\Models\StageRejectLog;
use App\Models\StageWasteLog;
use App\Services\StageInputsService;
use Illuminate\Http\Request;

/**
 * Phase 4 — HTTP layer for stage waste and reject logs.
 *
 * Permission gating happens at the route level. Service does the
 * stage-state validation. Controller's job is photo upload handling
 * + translating HTTP <-> service.
 */
class StageInputsController extends Controller
{
    public function __construct(
        protected StageInputsService $service,
    ) {
    }

    // ── Waste ────────────────────────────────────────────────────────

    /**
     * GET /api/v2/stage-inputs/waste
     * Query params: order_id, order_stage_id, per_page (1-100, default 20)
     */
    public function indexWaste(Request $request)
    {
        $query = StageWasteLog::query()
            ->with(['loggedBy', 'stage'])
            ->latest('id');

        if ($orderId = $request->query('order_id')) {
            $query->where('order_id', $orderId);
        }
        if ($stageId = $request->query('order_stage_id')) {
            $query->where('order_stage_id', $stageId);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        return StageWasteLogResource::collection($query->paginate($perPage));
    }

    /**
     * POST /api/v2/stage-inputs/waste  (multipart/form-data)
     */
    public function storeWaste(StoreStageWasteLog $request)
    {
        $data = $request->validated();

        // Stash the photo on the public disk if one was uploaded.
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            $data['photo_path'] = $request->file('photo')->store(
                'stage-inputs/waste',
                'public',
            );
        }
        unset($data['photo']);

        $log = $this->service->logWaste($data, $request->user());

        return (new StageWasteLogResource($log->load(['loggedBy', 'stage'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * DELETE /api/v2/stage-inputs/waste/{id}   (managers only)
     */
    public function destroyWaste(int $id, Request $request)
    {
        $this->service->deleteWasteLog($id, $request->user());
        return response()->json(['message' => 'Waste log deleted'], 200);
    }

    // ── Reject ───────────────────────────────────────────────────────

    public function indexReject(Request $request)
    {
        $query = StageRejectLog::query()
            ->with(['loggedBy', 'stage'])
            ->latest('id');

        if ($orderId = $request->query('order_id')) {
            $query->where('order_id', $orderId);
        }
        if ($stageId = $request->query('order_stage_id')) {
            $query->where('order_stage_id', $stageId);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        return StageRejectLogResource::collection($query->paginate($perPage));
    }

    public function storeReject(StoreStageRejectLog $request)
    {
        $data = $request->validated();

        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            $data['photo_path'] = $request->file('photo')->store(
                'stage-inputs/reject',
                'public',
            );
        }
        unset($data['photo']);

        $log = $this->service->logReject($data, $request->user());

        return (new StageRejectLogResource($log->load(['loggedBy', 'stage'])))
            ->response()
            ->setStatusCode(201);
    }

    public function destroyReject(int $id, Request $request)
    {
        $this->service->deleteRejectLog($id, $request->user());
        return response()->json(['message' => 'Reject log deleted'], 200);
    }
}
