<?php

namespace App\Services;

use App\Models\CourierList;
use App\Models\ShippingMethod;
use App\Models\StageSubcontractAssignment;
use App\Models\StageSubcontractShipment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-I — Logistics portal aggregator.
 *
 * Two list endpoints + two detail endpoints:
 *
 *   listActiveShipments()  — landing list for the Subcontract tab.
 *                            Returns counts (for_pickup/in_transit/
 *                            delivered/issue) + a flat array of all
 *                            shipments across assignments.
 *
 *   shipmentContext($id)   — full detail view for one shipment.
 *
 *   assignmentContext($id) — context when an assignment exists but no
 *                            shipment row does yet (the "create
 *                            shipment" entry point).
 */
class LogisticsPortalService
{
    /**
     * Active-shipments landing.
     *
     * Pulls all shipments whose status is for_pickup/in_transit/
     * delivered/issue (i.e., everything — there's no "archived" state
     * yet). Wraps with the related assignment + vendor for the table.
     */
    public function listActiveShipments(): array
    {
        $shipments = StageSubcontractShipment::with([
            'assignment.subcontractor:id,name,address,contact_number',
            'assignment.stage:id,order_id,stage,status',
            'assignment.order:id,po_code,client_name,client_brand,shirt_color',
            'courier:id,name',
            'shippingMethod:id,name',
        ])
            ->orderByRaw("CASE status "
                . "WHEN 'for_pickup' THEN 1 "
                . "WHEN 'in_transit' THEN 2 "
                . "WHEN 'issue'      THEN 3 "
                . "WHEN 'delivered'  THEN 4 "
                . "ELSE 5 END")
            ->orderBy('updated_at', 'desc')
            ->get();

        // Also include "fresh" assignments that have NO shipment yet —
        // these are the entry points for creating a new shipment.
        $assignmentIdsWithShipments = $shipments->pluck('stage_subcontract_assignment_id')
            ->unique()
            ->all();

        $bareAssignments = StageSubcontractAssignment::with([
            'subcontractor:id,name,address,contact_number',
            'stage:id,order_id,stage,status',
            'order:id,po_code,client_name,client_brand,shirt_color',
        ])
            ->whereIn('status', [
                StageSubcontractAssignment::STATUS_PENDING,
                StageSubcontractAssignment::STATUS_OUT,
            ])
            ->when(! empty($assignmentIdsWithShipments), function ($q) use ($assignmentIdsWithShipments) {
                $q->whereNotIn('id', $assignmentIdsWithShipments);
            })
            ->orderBy('updated_at', 'desc')
            ->get();

        // Counts (overview cards from the mockup).
        $counts = [
            'for_pickup' => 0,
            'in_transit' => 0,
            'delivered'  => 0,
            'issue'      => 0,
        ];
        foreach ($shipments as $s) {
            if (isset($counts[$s->status])) {
                $counts[$s->status]++;
            }
        }
        // Bare assignments (no shipment yet) are "for_pickup" semantically.
        $counts['for_pickup'] += $bareAssignments->count();

        return [
            'counts'    => $counts,
            'shipments' => $shipments->map(fn ($s) => $this->summarizeShipment($s))->all(),
            'pending_assignments' => $bareAssignments
                ->map(fn ($a) => $this->summarizeBareAssignment($a))
                ->all(),
        ];
    }

    /**
     * Full detail for one shipment. Used by the detail view.
     */
    public function shipmentContext(int $shipmentId): array
    {
        $shipment = StageSubcontractShipment::with([
            'assignment.subcontractor',
            'assignment.stage',
            'assignment.order',
            'courier',
            'shippingMethod',
        ])->find($shipmentId);

        if (! $shipment) {
            throw ValidationException::withMessages([
                'id' => 'Shipment not found.',
            ]);
        }

        $assignment = $shipment->assignment;
        $order      = $assignment?->order;
        $vendor     = $assignment?->subcontractor;
        $stage      = $assignment?->stage;

        return [
            'shipment'           => $this->presentShipment($shipment),
            'assignment'         => $assignment ? $this->presentAssignment($assignment) : null,
            'order'              => $order ? [
                'id'              => $order->id,
                'po_code'         => $order->po_code,
                'client_name'     => $order->client_name,
                'client_brand'    => $order->client_brand,
                'shirt_color'     => $order->shirt_color,
                'workflow_status' => $order->workflow_status,
            ] : null,
            'subcontractor'      => $vendor ? [
                'id'              => $vendor->id,
                'name'            => $vendor->name,
                'address'         => $vendor->address,
                'contact_number'  => $vendor->contact_number,
                'service_type'    => $vendor->service_type,
            ] : null,
            'stage'              => $stage ? [
                'id'    => $stage->id,
                'stage' => $stage->stage,
                'status'=> $stage->status,
            ] : null,
            'courier_options'    => $this->courierOptions(),
            'shipping_method_options' => $this->shippingMethodOptions(),
            'sibling_shipments'  => $assignment
                ? StageSubcontractShipment::where('stage_subcontract_assignment_id', $assignment->id)
                    ->where('id', '!=', $shipment->id)
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->map(fn ($s) => $this->summarizeShipment($s))
                    ->all()
                : [],
        ];
    }

    /**
     * Context for a bare assignment (no shipment row yet). Used as the
     * landing for "Create shipment" from the list view.
     */
    public function assignmentContext(int $assignmentId): array
    {
        $assignment = StageSubcontractAssignment::with([
            'subcontractor',
            'stage',
            'order',
        ])->find($assignmentId);

        if (! $assignment) {
            throw ValidationException::withMessages([
                'id' => 'Assignment not found.',
            ]);
        }

        return [
            'assignment'    => $this->presentAssignment($assignment),
            'order'         => $assignment->order ? [
                'id'              => $assignment->order->id,
                'po_code'         => $assignment->order->po_code,
                'client_name'     => $assignment->order->client_name,
                'client_brand'    => $assignment->order->client_brand,
                'shirt_color'     => $assignment->order->shirt_color,
                'workflow_status' => $assignment->order->workflow_status,
            ] : null,
            'subcontractor' => $assignment->subcontractor ? [
                'id'             => $assignment->subcontractor->id,
                'name'           => $assignment->subcontractor->name,
                'address'        => $assignment->subcontractor->address,
                'contact_number' => $assignment->subcontractor->contact_number,
                'service_type'   => $assignment->subcontractor->service_type,
            ] : null,
            'stage'         => $assignment->stage ? [
                'id'    => $assignment->stage->id,
                'stage' => $assignment->stage->stage,
                'status'=> $assignment->stage->status,
            ] : null,
            'courier_options'    => $this->courierOptions(),
            'shipping_method_options' => $this->shippingMethodOptions(),
            'shipments'     => StageSubcontractShipment::where(
                'stage_subcontract_assignment_id',
                $assignment->id,
            )->orderBy('created_at', 'asc')->get()
                ->map(fn ($s) => $this->summarizeShipment($s))
                ->all(),
        ];
    }

    // ── Presenters ──────────────────────────────────────────────────

    protected function summarizeShipment(StageSubcontractShipment $s): array
    {
        $assignment = $s->assignment;
        $order      = $assignment?->order;
        $vendor     = $assignment?->subcontractor;
        $stage      = $assignment?->stage;

        return [
            'id'                => $s->id,
            'assignment_id'     => $s->stage_subcontract_assignment_id,
            'direction'         => $s->direction,
            'status'            => $s->status,
            'delivery_mode'     => $s->delivery_mode,
            'courier'           => $s->courier ? $s->courier->name : null,
            'shipping_method'   => $s->shippingMethod ? $s->shippingMethod->name : null,
            'waybill_number'    => $s->waybill_number,
            'booking_time'      => $s->booking_time?->toDateTimeString(),
            'departure_time'    => $s->departure_time?->toDateTimeString(),
            'delivered_at'      => $s->delivered_at?->toDateTimeString(),
            'updated_at'        => $s->updated_at?->toDateTimeString(),
            'order' => $order ? [
                'id'           => $order->id,
                'po_code'      => $order->po_code,
                'client_brand' => $order->client_brand,
            ] : null,
            'vendor' => $vendor ? [
                'id'   => $vendor->id,
                'name' => $vendor->name,
            ] : null,
            'stage' => $stage ? [
                'id'    => $stage->id,
                'stage' => $stage->stage,
            ] : null,
        ];
    }

    protected function summarizeBareAssignment(StageSubcontractAssignment $a): array
    {
        return [
            'id'             => $a->id,
            'status'         => $a->status,
            'quantity_pcs'   => (int) $a->quantity_pcs,
            'order' => $a->order ? [
                'id'           => $a->order->id,
                'po_code'      => $a->order->po_code,
                'client_brand' => $a->order->client_brand,
            ] : null,
            'vendor' => $a->subcontractor ? [
                'id'   => $a->subcontractor->id,
                'name' => $a->subcontractor->name,
            ] : null,
            'stage' => $a->stage ? [
                'id'    => $a->stage->id,
                'stage' => $a->stage->stage,
            ] : null,
            'no_shipment_yet' => true,
        ];
    }

    protected function presentShipment(StageSubcontractShipment $s): array
    {
        $publicUrl = fn ($p) => $p ? $this->publicUrl($p) : null;

        return [
            'id'                       => $s->id,
            'assignment_id'            => $s->stage_subcontract_assignment_id,
            'direction'                => $s->direction,
            'status'                   => $s->status,
            'delivery_mode'            => $s->delivery_mode,

            'courier_id'               => $s->courier_id,
            'shipping_method_id'       => $s->shipping_method_id,
            'waybill_number'           => $s->waybill_number,

            'pickup_address'           => $s->pickup_address,
            'dropoff_address'          => $s->dropoff_address,
            'contact_person_name'      => $s->contact_person_name,
            'contact_person_number'    => $s->contact_person_number,
            'instructions'             => $s->instructions,

            'booking_time'             => $s->booking_time?->toDateTimeString(),
            'departure_time'           => $s->departure_time?->toDateTimeString(),
            'delivered_at'             => $s->delivered_at?->toDateTimeString(),
            'issue_note'               => $s->issue_note,

            'payment_amount'           => $s->payment_amount !== null ? (float) $s->payment_amount : null,
            'payment_method'           => $s->payment_method,
            'payment_reference'        => $s->payment_reference,
            'payment_proof_path'       => $s->payment_proof_path,
            'payment_proof_url'        => $publicUrl($s->payment_proof_path),

            'pickup_proof_path'        => $s->pickup_proof_path,
            'pickup_proof_url'         => $publicUrl($s->pickup_proof_path),

            'delivery_proof_path'      => $s->delivery_proof_path,
            'delivery_proof_url'       => $publicUrl($s->delivery_proof_path),
            'receiver_signature_path'  => $s->receiver_signature_path,
            'receiver_signature_url'   => $publicUrl($s->receiver_signature_path),
            'receiver_name'            => $s->receiver_name,

            'driver_name'              => $s->driver_name,
            'driver_vehicle_plate'     => $s->driver_vehicle_plate,
            'gas_receipt_path'         => $s->gas_receipt_path,
            'gas_receipt_url'          => $publicUrl($s->gas_receipt_path),
            'gas_amount'               => $s->gas_amount !== null ? (float) $s->gas_amount : null,
            'gas_date'                 => $s->gas_date?->toDateString(),
            'gas_notes'                => $s->gas_notes,

            'created_by'               => $s->created_by_user_id,
            'created_at'               => $s->created_at?->toDateTimeString(),
            'updated_at'               => $s->updated_at?->toDateTimeString(),
        ];
    }

    protected function presentAssignment(StageSubcontractAssignment $a): array
    {
        $publicUrl = fn ($p) => $p ? $this->publicUrl($p) : null;

        return [
            'id'                          => $a->id,
            'order_id'                    => $a->order_id,
            'order_stage_id'              => $a->order_stage_id,
            'subcontractor_id'            => $a->subcontractor_id,
            'quantity_pcs'                => (int) $a->quantity_pcs,
            'rate_per_pcs'                => (float) $a->rate_per_pcs,
            'total_amount'                => (float) $a->total_amount,
            'status'                      => $a->status,
            'sent_at'                     => $a->sent_at?->toDateTimeString(),
            'returned_at'                 => $a->returned_at?->toDateTimeString(),
            'expected_return_at'          => $a->expected_return_at?->toDateTimeString(),
            'turnover_method'             => $a->turnover_method,
            'payment_terms'               => $a->payment_terms,
            'waybill_number'              => $a->waybill_number,
            'notes'                       => $a->notes,

            // Return verification fields
            'return_qty_received'         => $a->return_qty_received !== null ? (int) $a->return_qty_received : null,
            'return_condition_notes'      => $a->return_condition_notes,
            'return_photo_front_path'     => $a->return_photo_front_path,
            'return_photo_front_url'      => $publicUrl($a->return_photo_front_path),
            'return_photo_back_path'      => $a->return_photo_back_path,
            'return_photo_back_url'       => $publicUrl($a->return_photo_back_path),
            'return_verified_by_user_id'  => $a->return_verified_by_user_id,
            'return_verified_at'          => $a->return_verified_at?->toDateTimeString(),
        ];
    }

    protected function courierOptions(): array
    {
        return CourierList::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    protected function shippingMethodOptions(): array
    {
        return ShippingMethod::with('courier:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'courier_id'])
            ->map(fn ($m) => [
                'id'         => $m->id,
                'name'       => $m->name,
                'courier_id' => $m->courier_id,
                'courier'    => $m->courier ? $m->courier->name : null,
            ])
            ->all();
    }

    protected function publicUrl(string $path): string
    {
        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'storage/')) {
            return '/' . $relative;
        }
        return Storage::disk('public')->url($relative);
    }
}
