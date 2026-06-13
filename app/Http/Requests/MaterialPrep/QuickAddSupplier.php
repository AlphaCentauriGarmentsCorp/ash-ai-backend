<?php

namespace App\Http\Requests\MaterialPrep;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Issue 20 — Quick-add a supplier inline from the PR supplier picker.
 *
 * Minimal surface: a name plus ONE optional order-channel link. The created
 * supplier is flagged is_incomplete=true (contact/address left blank) so the
 * Purchaser can "complete later" via the full Material Suppliers edit form.
 * Writes to the same `suppliers` table — one source of truth.
 *
 * Expected shape:
 * {
 *   "name": "Tela Supplier — Divisoria",
 *   "channel_type": "viber",          // optional; required when channel_url present
 *   "channel_url":  "viber://chat?..."  // optional
 * }
 */
class QuickAddSupplier extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'channel_url'  => 'nullable|string|max:1000',
            'channel_type' => 'required_with:channel_url|nullable|string|in:viber,messenger,facebook,shopee,lazada,tiktok,website,phone,other',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'         => 'Pangalan ng supplier ang kailangan.',
            'channel_type.required_with' => 'Pumili ng uri ng order channel.',
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
