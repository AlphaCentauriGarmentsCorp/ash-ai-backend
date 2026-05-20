<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaterialRequest\RejectMaterialRequest;
use App\Http\Requests\MaterialRequest\StoreMaterialRequest;
use App\Http\Resources\MaterialRequestResource;
use App\Models\MaterialRequest;
use App\Services\MaterialRequestService;
use Illuminate\Http\Request;

/**
 * Phase 3 — HTTP layer for Material Requests.
 *
 * Permission gating happens at the route level via Spatie's
 * `permission:` middleware. Stage-restriction is enforced by the
 * service. This controller's job is to translate HTTP <-> service.
 */
class MaterialRequestsController extends Controller
{
    public function __construct(
        protected MaterialRequestService $service,
    ) {
    }

    /**
     * GET /api/v2/material-requests
     *
     * Query params:
     *   status    – pending | approved | rejected | auto_pr
     *   order_id  – filter by order
     *   mine      – truthy = only requests by the current user
     *   per_page  – pagination size (default 20)
     */
    public function index(Request $request)
    {
        $query = MaterialRequest::query()
            ->with([
                'order',
                'stage',
                'requestedBy',
                'approvedBy',
                'items.material',
                'purchaseRequest',
            ])
            ->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($orderId = $request->query('order_id')) {
            $query->where('order_id', $orderId);
        }

        if ($request->boolean('mine')) {
            $query->where('requested_by_user_id', $request->user()->id);
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(100, $perPage));

        return MaterialRequestResource::collection($query->paginate($perPage));
    }

    /**
     * GET /api/v2/material-requests/{id}
     */
    public function show(int $id)
    {
        $mr = MaterialRequest::with([
            'order',
            'stage',
            'requestedBy',
            'approvedBy',
            'items.material',
            'purchaseRequest',
        ])->findOrFail($id);

        return new MaterialRequestResource($mr);
    }

    /**
     * POST /api/v2/material-requests
     */
    public function store(StoreMaterialRequest $request)
    {
        $mr = $this->service->create($request->validated(), $request->user());

        // Notification fires after the create transaction has committed.
        $this->service->announceCreated($mr);

        return (new MaterialRequestResource($mr->load([
            'order',
            'stage',
            'requestedBy',
            'items.material',
        ])))->response()->setStatusCode(201);
    }

    /**
     * POST /api/v2/material-requests/{id}/approve
     */
    public function approve(int $id, Request $request)
    {
        $mr = MaterialRequest::findOrFail($id);
        $mr = $this->service->approve($mr, $request->user());

        $this->service->announceDecided($mr);

        return new MaterialRequestResource($mr->load([
            'order',
            'stage',
            'requestedBy',
            'approvedBy',
            'items.material',
            'purchaseRequest',
        ]));
    }

    /**
     * POST /api/v2/material-requests/{id}/reject
     */
    public function reject(int $id, RejectMaterialRequest $request)
    {
        $mr = MaterialRequest::findOrFail($id);
        $mr = $this->service->reject(
            $mr,
            $request->validated()['rejection_reason'],
            $request->user(),
        );

        $this->service->announceDecided($mr);

        return new MaterialRequestResource($mr->load([
            'order',
            'stage',
            'requestedBy',
            'approvedBy',
            'items.material',
        ]));
    }
}
