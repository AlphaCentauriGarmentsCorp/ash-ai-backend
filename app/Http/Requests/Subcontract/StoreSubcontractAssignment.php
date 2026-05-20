<?php

namespace App\Http\Requests\Subcontract;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 4 — Validates the create-subcontract-assignment payload.
 *
 * No file upload — JSON request.
 *
 * Fields:
 *   - order_id          (required, FK)
 *   - order_stage_id    (required, FK)
 *   - subcontractor_id  (required, FK to subcontractors)
 *   - quantity_pcs      (required int >= 1)
 *   - notes             (optional, ≤ 1000 chars)
 *
 * The vendor's rate is snapshotted at assignment time by the service —
 * no need to send it from the client.
 */
class StoreSubcontractAssignment extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'         => 'required|integer|exists:orders,id',
            'order_stage_id'   => 'required|integer|exists:order_stages,id',
            'subcontractor_id' => 'required|integer|exists:subcontractors,id',
            'quantity_pcs'     => 'required|integer|min:1',
            'notes'            => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'quantity_pcs.min' => 'Quantity must be at least 1 piece.',
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
