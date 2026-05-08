<?php

namespace App\Http\Requests\MaterialRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 3 — Validates the create-MR payload.
 *
 * Expected shape:
 * {
 *   "order_id": 7,
 *   "reason": "Need 5 yards red thread for stage",
 *   "items": [
 *     { "material_id": 12, "quantity_requested": 5, "notes": "..." },
 *     { "material_id": 18, "quantity_requested": 2 }
 *   ]
 * }
 */
class StoreMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission gate is applied at the route level via the
        // permission:material_requests.create middleware. Stage-restriction
        // is enforced inside MaterialRequestService::create.
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'             => 'required|integer|exists:orders,id',
            'reason'               => 'nullable|string|max:1000',
            'items'                => 'required|array|min:1',
            'items.*.material_id'  => 'required|integer|exists:materials,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
            'items.*.notes'        => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one material item is required.',
            'items.min'      => 'At least one material item is required.',
            'items.*.material_id.required' => 'Each item must reference a material.',
            'items.*.quantity_requested.min' => 'Quantity requested must be greater than 0.',
        ];
    }

    /**
     * Standardise the 422 error response so the frontend's existing
     * inline-error handler shows useful messages.
     */
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
