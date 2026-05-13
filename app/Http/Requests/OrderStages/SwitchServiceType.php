<?php

namespace App\Http\Requests\OrderStages;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 5-D — Validates the service-type switch request.
 *
 * Fields:
 *   - service_type   required, must be 'in_house' or 'subcontract'
 *   - reason         optional, max 500 chars — captured in audit log
 */
class SwitchServiceType extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type' => 'required|in:in_house,subcontract',
            'reason'       => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'service_type.in' => 'Service type must be in_house or subcontract.',
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
