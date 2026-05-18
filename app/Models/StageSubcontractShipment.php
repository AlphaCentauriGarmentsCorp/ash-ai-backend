<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5-I — Logistics shipment record for a subcontract assignment.
 *
 * One assignment can have multiple shipments (outbound + inbound_return).
 * Shipment status is the lifecycle the Logistics staff drives:
 *   for_pickup → in_transit → delivered (or → issue)
 *
 * This is distinct from the assignment-level status (pending/out/
 * returned/cancelled) from Phase 4 / 5-D, which tracks the higher-level
 * subcontract relationship.
 */
class StageSubcontractShipment extends Model
{
    protected $table = 'stage_subcontract_shipments';

    public const DIRECTION_OUTBOUND       = 'outbound';
    public const DIRECTION_INBOUND_RETURN = 'inbound_return';

    public const DIRECTIONS = [
        self::DIRECTION_OUTBOUND,
        self::DIRECTION_INBOUND_RETURN,
    ];

    public const STATUS_FOR_PICKUP = 'for_pickup';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED  = 'delivered';
    public const STATUS_ISSUE      = 'issue';

    public const STATUSES = [
        self::STATUS_FOR_PICKUP,
        self::STATUS_IN_TRANSIT,
        self::STATUS_DELIVERED,
        self::STATUS_ISSUE,
    ];

    public const MODE_COURIER         = 'courier';
    public const MODE_IN_HOUSE_DRIVER = 'in_house_driver';

    public const MODES = [
        self::MODE_COURIER,
        self::MODE_IN_HOUSE_DRIVER,
    ];

    /**
     * Logical groupings of "proof" fields. The proof-upload endpoint
     * accepts a `kind` parameter and writes to the matching column.
     */
    public const PROOF_KINDS = [
        'payment'      => 'payment_proof_path',
        'pickup'       => 'pickup_proof_path',
        'delivery'     => 'delivery_proof_path',
        'signature'    => 'receiver_signature_path',
        'gas_receipt'  => 'gas_receipt_path',
    ];

    protected $fillable = [
        'stage_subcontract_assignment_id',
        'direction',
        'status',
        'delivery_mode',

        'courier_id',
        'shipping_method_id',
        'waybill_number',

        'pickup_address',
        'dropoff_address',
        'contact_person_name',
        'contact_person_number',
        'instructions',

        'booking_time',
        'departure_time',
        'delivered_at',
        'issue_note',

        'payment_amount',
        'payment_method',
        'payment_reference',
        'payment_proof_path',

        'pickup_proof_path',
        'delivery_proof_path',
        'receiver_signature_path',
        'receiver_name',

        'driver_name',
        'driver_vehicle_plate',
        'gas_receipt_path',
        'gas_amount',
        'gas_date',
        'gas_notes',

        'created_by_user_id',
    ];

    protected $casts = [
        'booking_time'   => 'datetime',
        'departure_time' => 'datetime',
        'delivered_at'   => 'datetime',
        'payment_amount' => 'decimal:2',
        'gas_amount'     => 'decimal:2',
        'gas_date'       => 'date',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(
            StageSubcontractAssignment::class,
            'stage_subcontract_assignment_id',
        );
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(CourierList::class, 'courier_id');
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
