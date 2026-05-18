<?php

namespace App\Services;

use App\Models\StageSubcontractAssignment;
use App\Models\StageSubcontractShipment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5-I — Shipment lifecycle for subcontract assignments.
 *
 * Three operations:
 *   - create() — open a new shipment row for an assignment (defaults
 *                to direction=outbound, status=for_pickup)
 *   - update() — patch shipment fields (no files)
 *   - transitionStatus() — move shipment status forward (or to issue)
 *                          and record the matching timestamp
 *   - uploadProof()    — attach a proof file to one of the proof_* columns
 *
 * The assignment-level state machine (pending/out/returned/cancelled)
 * is owned by SubcontractService and is NOT modified here. Shipments
 * are a parallel concept.
 */
class SubcontractShipmentService
{
    /**
     * Open a new shipment for an assignment.
     */
    public function create(array $data, ?User $actor = null): StageSubcontractShipment
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'action.manage-subcontract');

        $this->validateDirection($data['direction'] ?? StageSubcontractShipment::DIRECTION_OUTBOUND);
        $this->validateDeliveryMode($data['delivery_mode'] ?? StageSubcontractShipment::MODE_COURIER);

        return DB::transaction(function () use ($data, $actor) {
            $assignment = StageSubcontractAssignment::lockForUpdate()
                ->find($data['stage_subcontract_assignment_id']);
            if (! $assignment) {
                throw ValidationException::withMessages([
                    'stage_subcontract_assignment_id' => 'Assignment not found.',
                ]);
            }

            // Whitelist all writable fields so callers can't sneak in
            // status/direction values via the create call beyond the
            // validated set.
            $payload = [
                'stage_subcontract_assignment_id' => $assignment->id,
                'direction'           => $data['direction']           ?? StageSubcontractShipment::DIRECTION_OUTBOUND,
                'status'              => StageSubcontractShipment::STATUS_FOR_PICKUP,
                'delivery_mode'       => $data['delivery_mode']       ?? StageSubcontractShipment::MODE_COURIER,
                'courier_id'          => $data['courier_id']          ?? null,
                'shipping_method_id'  => $data['shipping_method_id']  ?? null,
                'waybill_number'      => $data['waybill_number']      ?? null,
                'pickup_address'      => $data['pickup_address']      ?? null,
                'dropoff_address'     => $data['dropoff_address']     ?? null,
                'contact_person_name' => $data['contact_person_name'] ?? null,
                'contact_person_number' => $data['contact_person_number'] ?? null,
                'instructions'        => $data['instructions']        ?? null,
                'booking_time'        => $data['booking_time']        ?? null,
                'payment_amount'      => $data['payment_amount']      ?? null,
                'payment_method'      => $data['payment_method']      ?? null,
                'payment_reference'   => $data['payment_reference']   ?? null,
                'driver_name'         => $data['driver_name']         ?? null,
                'driver_vehicle_plate' => $data['driver_vehicle_plate'] ?? null,
                'gas_amount'          => $data['gas_amount']          ?? null,
                'gas_date'            => $data['gas_date']            ?? null,
                'gas_notes'           => $data['gas_notes']           ?? null,
                'created_by_user_id'  => $actor->id,
            ];

            return StageSubcontractShipment::create($payload);
        });
    }

    /**
     * Patch shipment fields. Does not change status — use
     * transitionStatus() for that. Does not handle file uploads — use
     * uploadProof().
     */
    public function update(int $shipmentId, array $data, ?User $actor = null): StageSubcontractShipment
    {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'action.manage-subcontract');

        return DB::transaction(function () use ($shipmentId, $data) {
            $shipment = StageSubcontractShipment::lockForUpdate()->find($shipmentId);
            if (! $shipment) {
                throw ValidationException::withMessages(['id' => 'Shipment not found.']);
            }

            $writableFields = [
                'delivery_mode',
                'courier_id', 'shipping_method_id', 'waybill_number',
                'pickup_address', 'dropoff_address',
                'contact_person_name', 'contact_person_number', 'instructions',
                'booking_time', 'departure_time',
                'payment_amount', 'payment_method', 'payment_reference',
                'receiver_name',
                'driver_name', 'driver_vehicle_plate',
                'gas_amount', 'gas_date', 'gas_notes',
                'issue_note',
            ];

            $patch = [];
            foreach ($writableFields as $f) {
                if (array_key_exists($f, $data)) {
                    $patch[$f] = $data[$f];
                }
            }

            if (isset($patch['delivery_mode'])) {
                $this->validateDeliveryMode($patch['delivery_mode']);
            }

            $shipment->update($patch);
            return $shipment->fresh();
        });
    }

    /**
     * Move shipment status forward. Records the appropriate timestamp
     * automatically (booking_time/departure_time/delivered_at) so the
     * frontend only has to send the target status.
     *
     * Allowed transitions:
     *   for_pickup → in_transit
     *   for_pickup → issue
     *   in_transit → delivered
     *   in_transit → issue
     *   delivered  → issue        (rare, but possible if discovered after)
     *   issue      → in_transit   (recovery)
     *   issue      → delivered    (recovery, e.g., proof located)
     */
    public function transitionStatus(
        int $shipmentId,
        string $toStatus,
        ?string $issueNote,
        ?User $actor = null,
    ): StageSubcontractShipment {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'action.manage-subcontract');

        if (! in_array($toStatus, StageSubcontractShipment::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => "Invalid status '{$toStatus}'.",
            ]);
        }

        return DB::transaction(function () use ($shipmentId, $toStatus, $issueNote) {
            $shipment = StageSubcontractShipment::lockForUpdate()->find($shipmentId);
            if (! $shipment) {
                throw ValidationException::withMessages(['id' => 'Shipment not found.']);
            }

            $from = $shipment->status;
            if (! $this->canTransition($from, $toStatus)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot move from '{$from}' to '{$toStatus}'.",
                ]);
            }

            $patch = ['status' => $toStatus];

            // Auto-record the matching timestamp on first transition.
            if ($toStatus === StageSubcontractShipment::STATUS_IN_TRANSIT) {
                if ($shipment->delivery_mode === StageSubcontractShipment::MODE_IN_HOUSE_DRIVER) {
                    $patch['departure_time'] = $shipment->departure_time ?? now();
                } else {
                    $patch['booking_time'] = $shipment->booking_time ?? now();
                }
            }
            if ($toStatus === StageSubcontractShipment::STATUS_DELIVERED) {
                $patch['delivered_at'] = $shipment->delivered_at ?? now();
            }
            if ($toStatus === StageSubcontractShipment::STATUS_ISSUE) {
                if ($issueNote !== null && $issueNote !== '') {
                    $patch['issue_note'] = $issueNote;
                }
            }

            $shipment->update($patch);
            return $shipment->fresh();
        });
    }

    /**
     * Upload a proof file. Writes to the column matching $kind. Replaces
     * any prior file at that slot (the old file is deleted from disk).
     *
     * @param  string  $kind  One of StageSubcontractShipment::PROOF_KINDS keys:
     *                         payment | pickup | delivery | signature | gas_receipt
     */
    public function uploadProof(
        int $shipmentId,
        string $kind,
        string $relativePath,
        ?User $actor = null,
    ): StageSubcontractShipment {
        $actor = $actor ?? Auth::user();
        $this->ensureCan($actor, 'action.upload-photos');

        $kinds = StageSubcontractShipment::PROOF_KINDS;
        if (! array_key_exists($kind, $kinds)) {
            throw ValidationException::withMessages([
                'kind' => "Invalid proof kind '{$kind}'. Allowed: " . implode(', ', array_keys($kinds)),
            ]);
        }
        $column = $kinds[$kind];

        return DB::transaction(function () use ($shipmentId, $column, $relativePath) {
            $shipment = StageSubcontractShipment::lockForUpdate()->find($shipmentId);
            if (! $shipment) {
                throw ValidationException::withMessages(['id' => 'Shipment not found.']);
            }

            $oldPath = $shipment->{$column};
            $shipment->update([$column => $relativePath]);

            if ($oldPath && $oldPath !== $relativePath) {
                $this->deletePhysicalFile($oldPath);
            }

            return $shipment->fresh();
        });
    }

    // ── Helpers ────────────────────────────────────────────────────

    protected function canTransition(string $from, string $to): bool
    {
        $allowed = [
            StageSubcontractShipment::STATUS_FOR_PICKUP => [
                StageSubcontractShipment::STATUS_IN_TRANSIT,
                StageSubcontractShipment::STATUS_ISSUE,
            ],
            StageSubcontractShipment::STATUS_IN_TRANSIT => [
                StageSubcontractShipment::STATUS_DELIVERED,
                StageSubcontractShipment::STATUS_ISSUE,
            ],
            StageSubcontractShipment::STATUS_DELIVERED => [
                StageSubcontractShipment::STATUS_ISSUE,
            ],
            StageSubcontractShipment::STATUS_ISSUE => [
                StageSubcontractShipment::STATUS_IN_TRANSIT,
                StageSubcontractShipment::STATUS_DELIVERED,
            ],
        ];
        return in_array($to, $allowed[$from] ?? [], true);
    }

    protected function validateDirection(string $direction): void
    {
        if (! in_array($direction, StageSubcontractShipment::DIRECTIONS, true)) {
            throw ValidationException::withMessages([
                'direction' => "Invalid direction '{$direction}'.",
            ]);
        }
    }

    protected function validateDeliveryMode(string $mode): void
    {
        if (! in_array($mode, StageSubcontractShipment::MODES, true)) {
            throw ValidationException::withMessages([
                'delivery_mode' => "Invalid delivery mode '{$mode}'.",
            ]);
        }
    }

    protected function ensureCan(?User $actor, string $permission): void
    {
        if (! $actor) {
            throw ValidationException::withMessages(['actor' => 'No authenticated user.']);
        }
        if (! $actor->can($permission)) {
            throw ValidationException::withMessages([
                'permission' => "Missing permission: {$permission}",
            ]);
        }
    }

    protected function deletePhysicalFile(?string $path): void
    {
        if (! $path) return;
        $relative = ltrim($path, '/');
        if (str_starts_with($relative, 'storage/')) {
            $relative = substr($relative, strlen('storage/'));
        }
        Storage::disk('public')->delete($relative);
    }
}
