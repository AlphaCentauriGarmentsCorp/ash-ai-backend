<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreShipment;
use App\Http\Requests\Logistics\UpdateShipment;
use App\Http\Requests\Logistics\UpdateShipmentStatus;
use App\Http\Requests\Logistics\UploadShipmentProof;
use App\Http\Requests\Logistics\VerifyReturn;
use App\Services\LogisticsPortalService;
use App\Services\SubcontractReturnVerificationService;
use App\Services\SubcontractShipmentService;
use Illuminate\Http\Request;

/**
 * Phase 5-I — HTTP layer for the Logistics portal.
 *
 * Endpoints (all gated by portal.logistics):
 *
 *   GET    /api/v2/portal/logistics/active-shipments
 *   GET    /api/v2/portal/logistics/active-deliveries  (placeholder — coming-soon)
 *   GET    /api/v2/portal/logistics/shipment-context/{id}
 *   GET    /api/v2/portal/logistics/assignment-context/{id}
 *   POST   /api/v2/portal/logistics/shipments
 *   PUT    /api/v2/portal/logistics/shipments/{id}
 *   POST   /api/v2/portal/logistics/shipments/{id}/proof    (multipart)
 *   PATCH  /api/v2/portal/logistics/shipments/{id}/status
 *   POST   /api/v2/portal/logistics/assignments/{id}/verify-return  (multipart)
 *
 * The active-deliveries endpoint is a placeholder for the Customer
 * Delivery tab — returns an empty list with a "coming_soon" flag for now.
 */
class LogisticsPortalController extends Controller
{
    public function __construct(
        protected LogisticsPortalService $portal,
        protected SubcontractShipmentService $shipments,
        protected SubcontractReturnVerificationService $returns,
    ) {
    }

    // ── List + context ──────────────────────────────────────────────

    public function activeShipments()
    {
        $data = $this->portal->listActiveShipments();
        return response()->json(['data' => $data]);
    }

    /**
     * Placeholder for the Customer Delivery tab (Phase 5-I, D6).
     * The real implementation will arrive in a future layer; for now
     * the endpoint exists so the frontend tab has something to call.
     */
    public function activeDeliveries()
    {
        return response()->json([
            'data' => [
                'coming_soon' => true,
                'message'     => 'Customer Delivery workflow coming soon.',
                'deliveries'  => [],
            ],
        ]);
    }

    public function shipmentContext(int $id)
    {
        return response()->json([
            'data' => $this->portal->shipmentContext($id),
        ]);
    }

    public function assignmentContext(int $id)
    {
        return response()->json([
            'data' => $this->portal->assignmentContext($id),
        ]);
    }

    // ── Shipment writes ─────────────────────────────────────────────

    public function storeShipment(StoreShipment $request)
    {
        $shipment = $this->shipments->create(
            $request->validated(),
            $request->user(),
        );

        return response()->json([
            'data' => $this->portal->shipmentContext($shipment->id),
        ], 201);
    }

    public function updateShipment(int $id, UpdateShipment $request)
    {
        $this->shipments->update($id, $request->validated(), $request->user());

        return response()->json([
            'data' => $this->portal->shipmentContext($id),
        ]);
    }

    public function updateShipmentStatus(int $id, UpdateShipmentStatus $request)
    {
        $data = $request->validated();
        $this->shipments->transitionStatus(
            $id,
            $data['status'],
            $data['issue_note'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => $this->portal->shipmentContext($id),
        ]);
    }

    public function uploadProof(int $id, UploadShipmentProof $request)
    {
        $data = $request->validated();
        $file = $request->file('file');

        // Store under a per-shipment folder.
        $relativePath = $file->store(
            "logistics/shipments/{$id}",
            'public',
        );

        $this->shipments->uploadProof($id, $data['kind'], $relativePath, $request->user());

        return response()->json([
            'data' => $this->portal->shipmentContext($id),
        ], 201);
    }

    // ── Return verification ─────────────────────────────────────────

    public function verifyReturn(int $assignmentId, VerifyReturn $request)
    {
        $data = $request->validated();
        $patch = [
            'return_qty_received'    => (int) $data['return_qty_received'],
            'return_condition_notes' => $data['return_condition_notes'] ?? null,
        ];

        if ($request->hasFile('return_photo_front')
            && $request->file('return_photo_front')->isValid()) {
            $patch['return_photo_front_path'] = $request->file('return_photo_front')
                ->store("logistics/returns/{$assignmentId}", 'public');
        }
        if ($request->hasFile('return_photo_back')
            && $request->file('return_photo_back')->isValid()) {
            $patch['return_photo_back_path'] = $request->file('return_photo_back')
                ->store("logistics/returns/{$assignmentId}", 'public');
        }

        $assignment = $this->returns->verify($assignmentId, $patch, $request->user());

        return response()->json([
            'data' => $this->portal->assignmentContext($assignment->id),
        ], 201);
    }
}
