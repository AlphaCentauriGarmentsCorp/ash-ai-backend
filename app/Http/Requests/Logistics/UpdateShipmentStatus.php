<?php

namespace App\Http\Requests\Logistics;

use App\Models\StageSubcontractShipment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Phase 5-I — Status quick-action.
 *
 * Allowed transitions are enforced server-side in
 * SubcontractShipmentService::transitionStatus().
 */
class UpdateShipmentStatus extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'     => ['required', Rule::in(StageSubcontractShipment::STATUSES)],
            // Optional — required UX-wise only when moving to 'issue',
            // but enforced as nullable here so 'issue' can be set first
            // and the note edited later via UpdateShipment.
            'issue_note' => 'nullable|string|max:1000',
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
