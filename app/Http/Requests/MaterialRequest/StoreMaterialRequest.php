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
 *   "stage_id": 42,               // optional — the exact stage the request
 *                                 //  is for (a production portal passes the
 *                                 //  stage it launched from). When omitted,
 *                                 //  the service attaches the MR to the
 *                                 //  order's resolved current stage.
 *   "reason": "Need 5 yards red thread for stage",
 *   "items": [
 *     { "material_id": 12, "quantity_requested": 5, "notes": "..." },
 *     { "material_id": 18, "quantity_requested": 2 }
 *   ]
 * }
 *
 * SM Rework CP1 — `stage_id` was added because parallel forks
 * (screen_making ‖ material_prep_sample share sequence 6) make the
 * order's "current" stage ambiguous. A portal that knows exactly which
 * station the request is for passes stage_id so the MR attaches there
 * and reflects back in that portal's own Material Requests section.
 * The service validates that the stage belongs to the order.
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
            'stage_id'             => 'nullable|integer|exists:order_stages,id',
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
