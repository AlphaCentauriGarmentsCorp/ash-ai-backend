<?php

namespace App\Http\Requests\StageInputs;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 4 — Validates the create-reject-log payload.
 *
 * Same shape as StoreStageWasteLog. Kept separate so that future field
 * additions (e.g., `disposition` for reject-only) don't pollute waste
 * validation.
 */
class StoreStageRejectLog extends FormRequest
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
            'quantity_pcs'   => 'required|integer|min:1',
            'photo'          => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
            'notes'          => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'quantity_pcs.min' => 'Quantity must be at least 1 piece.',
            'photo.mimes'      => 'Photo must be a JPEG, PNG, or WebP file.',
            'photo.max'        => 'Photo must be smaller than 5 MB.',
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
