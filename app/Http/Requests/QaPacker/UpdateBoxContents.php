<?php

namespace App\Http\Requests\QaPacker;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 7-B Bundle 4a — Validate a contents update on a packing box.
 *
 * Sent as JSON. Caller may also send weight_kg in the same payload.
 *
 *   {
 *     "contents_json": [
 *       { "size": "S", "sku": "...", "qty": 10 },
 *       { "size": "M", "sku": "...", "qty": 25 }
 *     ],
 *     "weight_kg": 5.4   // optional
 *   }
 */
class UpdateBoxContents extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contents_json'        => 'required|array',
            'contents_json.*.size' => 'nullable|string|max:32',
            'contents_json.*.sku'  => 'nullable|string|max:64',
            'contents_json.*.qty'  => 'required|integer|min:0',
            'weight_kg'            => 'nullable|numeric|min:0|max:9999.99',
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
