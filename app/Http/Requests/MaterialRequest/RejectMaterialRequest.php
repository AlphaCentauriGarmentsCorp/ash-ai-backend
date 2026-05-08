<?php

namespace App\Http\Requests\MaterialRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RejectMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission gate is at route level (material_requests.reject).
        return true;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => 'required|string|min:3|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'A rejection reason is required.',
            'rejection_reason.min'      => 'Please provide a reason of at least 3 characters.',
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
