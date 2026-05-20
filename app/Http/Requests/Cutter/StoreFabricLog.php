<?php

namespace App\Http\Requests\Cutter;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 5-B — Validates fabric-log creation from the Cutter portal.
 *
 * Sent as JSON (no photo upload here — fabric logs don't carry photos).
 *
 * Fields:
 *   - order_id          (required FK)
 *   - order_stage_id    (required FK)
 *   - fabric_used_kg    (required decimal ≥ 0.01)
 *   - waste_kg          (optional decimal ≥ 0; must be ≤ fabric_used_kg)
 *   - fabric_roll_id    (optional string)
 *   - notes             (optional ≤ 1000 chars)
 */
class StoreFabricLog extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'        => 'required|integer|exists:orders,id',
            'order_stage_id'  => 'required|integer|exists:order_stages,id',
            'fabric_used_kg'  => 'required|numeric|min:0.01',
            'waste_kg'        => 'nullable|numeric|min:0',
            'fabric_roll_id'  => 'nullable|string|max:64',
            'notes'           => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'fabric_used_kg.min' => 'Fabric used must be at least 0.01 kg.',
            'waste_kg.min'       => 'Waste cannot be negative.',
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
