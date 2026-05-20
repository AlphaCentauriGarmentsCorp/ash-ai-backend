<?php

namespace App\Http\Requests\QaPacker;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 7-B Bundle 1 — Validates a reject OR repair log creation
 * from the QA/Packer portal.
 *
 * Sent as multipart (photo is optional but typical) OR JSON (no photo).
 *
 * Fields:
 *   - order_id           required FK
 *   - order_stage_id     required FK
 *   - disposition        required: 'reject' | 'repair'
 *   - reject_reason_id   required FK to reject_reasons
 *   - quantity_pcs       required int ≥ 1
 *   - photo              optional image upload (jpg/png/webp ≤ 8 MB)
 *   - notes              optional ≤ 1000 chars
 *
 * Bundle 1 supports the same payload for both reject and repair
 * dispositions — the UI splits the two flows but the wire format
 * is identical.
 */
class StoreReject extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route gate handles portal.qa-packer
    }

    public function rules(): array
    {
        return [
            'order_id'         => 'required|integer|exists:orders,id',
            'order_stage_id'   => 'required|integer|exists:order_stages,id',
            'disposition'      => 'required|in:reject,repair',
            'reject_reason_id' => 'required|integer|exists:reject_reasons,id',
            'quantity_pcs'     => 'required|integer|min:1',
            'photo'            => 'nullable|image|mimes:jpg,jpeg,png,webp|max:8192',
            'notes'            => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'disposition.in'      => "Disposition must be 'reject' or 'repair'.",
            'quantity_pcs.min'    => 'Quantity must be at least 1 piece.',
            'photo.max'           => 'Photo cannot exceed 8 MB.',
            'photo.mimes'         => 'Photo must be JPG, PNG, or WebP.',
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
