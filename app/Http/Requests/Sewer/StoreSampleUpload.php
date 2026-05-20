<?php

namespace App\Http\Requests\Sewer;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSampleUpload extends FormRequest
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
            'photo_front'     => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
            'photo_back'      => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
            'remarks'         => 'nullable|string|max:1000',
            'sample_status'   => 'nullable|in:pending,for_approval',
        ];
    }

    public function messages(): array
    {
        return [
            'photo_front.mimes' => 'Front photo must be a JPEG, PNG, or WebP.',
            'photo_back.mimes'  => 'Back photo must be a JPEG, PNG, or WebP.',
            'photo_front.max'   => 'Front photo must be smaller than 5 MB.',
            'photo_back.max'    => 'Back photo must be smaller than 5 MB.',
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
