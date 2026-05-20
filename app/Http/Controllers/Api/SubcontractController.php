<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subcontract\StoreSubcontractAssignment;
use App\Http\Resources\StageSubcontractAssignmentResource;
use App\Models\StageSubcontractAssignment;
use App\Services\SubcontractService;
use Illuminate\Http\Request;

/**
 * Phase 4 — HTTP layer for subcontract assignments.
 *
 * Lifecycle: pending → out → returned (or cancelled before return).
 */
class SubcontractController extends Controller
{
    public function __construct(
        protected SubcontractService $service,
    ) {
    }

    /**
     * GET /api/v2/subcontract-assignments
     * Query params: order_id, order_stage_id, subcontractor_id, status, per_page
     */
    public function index(Request $request)
    {
        $query = StageSubcontractAssignment::query()
            ->with(['subcontractor', 'stage'])
            ->latest('id');

        if ($orderId = $request->query('order_id')) {
            $query->where('order_id', $orderId);
        }
        if ($stageId = $request->query('order_stage_id')) {
            $query->where('order_stage_id', $stageId);
        }
        if ($vendorId = $request->query('subcontractor_id')) {
            $query->where('subcontractor_id', $vendorId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        return StageSubcontractAssignmentResource::collection($query->paginate($perPage));
    }

    public function show(int $id)
    {
        $assignment = StageSubcontractAssignment::with(['subcontractor', 'stage'])
            ->findOrFail($id);

        return new StageSubcontractAssignmentResource($assignment);
    }

    public function store(StoreSubcontractAssignment $request)
    {
        $assignment = $this->service->assign($request->validated(), $request->user());

        return (new StageSubcontractAssignmentResource($assignment->load(['subcontractor', 'stage'])))
            ->response()
            ->setStatusCode(201);
    }

    public function markSent(int $id, Request $request)
    {
        $assignment = $this->service->markSent($id, $request->user());
        return new StageSubcontractAssignmentResource($assignment->load(['subcontractor', 'stage']));
    }

    public function markReturned(int $id, Request $request)
    {
        $assignment = $this->service->markReturned($id, $request->user());
        return new StageSubcontractAssignmentResource($assignment->load(['subcontractor', 'stage']));
    }

    public function cancel(int $id, Request $request)
    {
        $assignment = $this->service->cancel($id, $request->user());
        return new StageSubcontractAssignmentResource($assignment->load(['subcontractor', 'stage']));
    }
}
