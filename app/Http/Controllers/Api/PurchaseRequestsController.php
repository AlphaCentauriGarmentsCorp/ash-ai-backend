<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseRequest\StorePurchaseRequest;
use App\Http\Resources\PurchaseRequestResource;
use App\Models\Materials;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Services\NotificationService;
use App\Services\PurchaseRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3 — HTTP layer for Purchase Requests.
 *
 * The auto-PR creation path is handled inside MaterialRequestService::approve
 * → PurchaseRequestService::createFromMaterialRequest. This controller
 * exposes the lifecycle transitions (approve/order/receive/cancel) and
 * an ad-hoc create endpoint for manager-initiated PRs.
 */
class PurchaseRequestsController extends Controller
{
    public function __construct(
        protected PurchaseRequestService $service,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * GET /api/v2/purchase-requests
     *
     * Query params:
     *   status      – pending | approved | ordered | received | cancelled
     *   order_id    – filter by order
     *   supplier_id – filter by supplier
     *   per_page    – pagination size (default 20)
     */
    public function index(Request $request)
    {
        $query = PurchaseRequest::query()
            ->with([
                'order',
                'supplier',
                'materialRequest',
                'approvedBy',
                'items.material',
            ])
            ->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($orderId = $request->query('order_id')) {
            $query->where('order_id', $orderId);
        }

        if ($supplierId = $request->query('supplier_id')) {
            $query->where('supplier_id', $supplierId);
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(100, $perPage));

        return PurchaseRequestResource::collection($query->paginate($perPage));
    }

    /**
     * GET /api/v2/purchase-requests/{id}
     */
    public function show(int $id)
    {
        $pr = PurchaseRequest::with([
            'order',
            'supplier',
            'materialRequest',
            'approvedBy',
            'items.material',
        ])->findOrFail($id);

        return new PurchaseRequestResource($pr);
    }

    /**
     * POST /api/v2/purchase-requests   (ad-hoc PR creation)
     *
     * Creates a PR without an originating MR. Used by purchasing/manager
     * for top-up purchases.
     */
    public function store(StorePurchaseRequest $request)
    {
        $data = $request->validated();

        $pr = DB::transaction(function () use ($data) {
            $code = $this->generateAdHocCode();

            $supplierId = $data['supplier_id'] ?? null;

            // If supplier wasn't specified, derive it from the first item's material.
            if (! $supplierId && ! empty($data['items'][0]['material_id'])) {
                $firstMaterial = Materials::find($data['items'][0]['material_id']);
                $supplierId = $firstMaterial?->supplier_id;
            }

            $pr = PurchaseRequest::create([
                'pr_code'      => $code,
                'order_id'     => $data['order_id'],
                'supplier_id'  => $supplierId,
                'status'       => PurchaseRequest::STATUS_PENDING,
                'reason'       => $data['reason'] ?? null,
                'total_amount' => 0,
            ]);

            $running = 0.0;
            foreach ($data['items'] as $row) {
                $material = Materials::findOrFail($row['material_id']);
                $qty       = (float) $row['quantity'];
                $unitPrice = isset($row['unit_price'])
                    ? (float) $row['unit_price']
                    : (float) ($material->price ?? 0);
                $lineTotal = round($qty * $unitPrice, 2);

                PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'material_id'         => $material->id,
                    'quantity'            => $qty,
                    'unit_price'          => $unitPrice,
                    'line_total'          => $lineTotal,
                    'unit'                => $material->unit,
                    'notes'               => $row['notes'] ?? null,
                ]);

                $running += $lineTotal;
            }

            $pr->update(['total_amount' => $running]);
            return $pr->fresh(['items.material', 'order', 'supplier']);
        }, 3);

        $this->service->announceCreated($pr);

        return (new PurchaseRequestResource($pr))->response()->setStatusCode(201);
    }

    /**
     * POST /api/v2/purchase-requests/{id}/approve
     */
    public function approve(int $id, Request $request)
    {
        $pr = PurchaseRequest::findOrFail($id);
        $pr = $this->service->approve($pr, $request->user());

        $this->service->announceDecided($pr, 'approved');

        return new PurchaseRequestResource($pr->load([
            'order', 'supplier', 'approvedBy', 'items.material', 'materialRequest',
        ]));
    }

    /**
     * POST /api/v2/purchase-requests/{id}/mark-ordered
     */
    public function markOrdered(int $id, Request $request)
    {
        $pr = PurchaseRequest::findOrFail($id);
        $pr = $this->service->markOrdered($pr, $request->user());

        $this->service->announceDecided($pr, 'ordered');

        return new PurchaseRequestResource($pr->load([
            'order', 'supplier', 'approvedBy', 'items.material', 'materialRequest',
        ]));
    }

    /**
     * POST /api/v2/purchase-requests/{id}/mark-received
     */
    public function markReceived(int $id, Request $request)
    {
        $pr = PurchaseRequest::findOrFail($id);
        $pr = $this->service->markReceived($pr, $request->user());

        $this->service->announceReceived($pr);

        return new PurchaseRequestResource($pr->load([
            'order', 'supplier', 'approvedBy', 'items.material', 'materialRequest',
        ]));
    }

    /**
     * POST /api/v2/purchase-requests/{id}/cancel
     */
    public function cancel(int $id, Request $request)
    {
        $pr = PurchaseRequest::findOrFail($id);
        $pr = $this->service->cancel($pr, $request->user());

        $this->service->announceDecided($pr, 'cancelled');

        return new PurchaseRequestResource($pr->load([
            'order', 'supplier', 'approvedBy', 'items.material', 'materialRequest',
        ]));
    }

    /**
     * Generate an ad-hoc PR code using the same shape as the auto-PR
     * service (PR-YYYY-NNNNNN). The service has its own private
     * generator; we use the same algorithm here.
     */
    protected function generateAdHocCode(): string
    {
        $year = now()->year;
        $count = PurchaseRequest::whereYear('created_at', $year)->count();

        for ($i = 1; $i <= 1000; $i++) {
            $candidate = sprintf('PR-%d-%06d', $year, $count + $i);
            if (! PurchaseRequest::where('pr_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return sprintf('PR-%d-%06d-%s', $year, $count + 1, substr(md5(uniqid()), 0, 6));
    }
}
