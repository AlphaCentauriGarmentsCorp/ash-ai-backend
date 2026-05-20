<?php

namespace App\Http\Requests\QaPacker;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 7-B Bundle 4a — Validate a single final-photo upload.
 *
 * Multipart. One file per call; the frontend manages the three slots
 * (completed_product, packed_boxes, shipping_photo) and uploads each
 * individually so failures don't cascade.
 *
 * Fields:
 *   - order_id       required FK
 *   - order_stage_id required FK
 *   - kind           required, one of: completed_product | packed_boxes | shipping_photo
 *   - photo          required image ≤ 8 MB
 */
class StoreFinalPhoto extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'       => 'required|integer|exists:orders,id',
            'order_stage_id' => 'required|integer|exists:order_stages,id',
            'kind'           => 'required|in:completed_product,packed_boxes,shipping_photo',
            'photo'          => 'required|image|mimes:jpg,jpeg,png,webp|max:8192',
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
