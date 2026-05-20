<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 5-I — Return-verification submission.
 *
 * multipart/form-data. Photos are optional but recommended.
 */
class VerifyReturn extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'return_qty_received'    => 'required|integer|min:0',
            'return_condition_notes' => 'nullable|string|max:1000',
            'return_photo_front'     => 'nullable|file|mimes:jpg,jpeg,png,webp,heic|max:10240',
            'return_photo_back'      => 'nullable|file|mimes:jpg,jpeg,png,webp,heic|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'return_qty_received.required' => 'Quantity received is required.',
            'return_qty_received.min'      => 'Quantity received cannot be negative.',
            'return_photo_front.mimes'     => 'Front photo must be JPG, PNG, WebP, or HEIC.',
            'return_photo_back.mimes'      => 'Back photo must be JPG, PNG, WebP, or HEIC.',
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
