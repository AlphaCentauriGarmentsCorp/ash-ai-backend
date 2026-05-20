<?php

namespace App\Http\Requests\Logistics;

use App\Models\StageSubcontractShipment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Phase 5-I — Create a new shipment for a subcontract assignment.
 *
 * No file uploads here — that goes through StoreShipmentProof.
 * Status is always 'for_pickup' on create; transitions go through
 * UpdateShipmentStatus.
 */
class StoreShipment extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage_subcontract_assignment_id' => 'required|integer|exists:stage_subcontract_assignments,id',
            'direction'             => ['nullable', Rule::in(StageSubcontractShipment::DIRECTIONS)],
            'delivery_mode'         => ['required', Rule::in(StageSubcontractShipment::MODES)],

            'courier_id'            => 'nullable|integer|exists:courier_list,id',
            'shipping_method_id'    => 'nullable|integer|exists:shipping_methods,id',
            'waybill_number'        => 'nullable|string|max:64',

            'pickup_address'        => 'nullable|string|max:500',
            'dropoff_address'       => 'nullable|string|max:500',
            'contact_person_name'   => 'nullable|string|max:120',
            'contact_person_number' => 'nullable|string|max:32',
            'instructions'          => 'nullable|string|max:1000',

            'booking_time'          => 'nullable|date',

            'payment_amount'        => 'nullable|numeric|min:0|max:99999999.99',
            'payment_method'        => 'nullable|string|max:32',
            'payment_reference'     => 'nullable|string|max:120',

            'driver_name'           => 'nullable|string|max:120',
            'driver_vehicle_plate'  => 'nullable|string|max:32',
            'gas_amount'            => 'nullable|numeric|min:0|max:99999999.99',
            'gas_date'              => 'nullable|date',
            'gas_notes'             => 'nullable|string|max:500',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors()->toArray(),
            ], 422),
        );
    }
}
