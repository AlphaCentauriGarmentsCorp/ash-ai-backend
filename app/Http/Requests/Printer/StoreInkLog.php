<?php

namespace App\Http\Requests\Printer;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 5-C — Validates ink-log creation from the Printer portal.
 *
 * Fields:
 *   - order_id          (required FK)
 *   - order_stage_id    (required FK)
 *   - ink_color         (optional string, e.g. "White", "Pantone 186 C")
 *   - ink_used_kg       (required decimal ≥ 0.001 — 3-decimal precision)
 *   - ink_waste_kg      (optional decimal ≥ 0; must be ≤ ink_used_kg)
 *   - notes             (optional ≤ 1000 chars)
 */
class StoreInkLog extends FormRequest
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
            'ink_color'      => 'nullable|string|max:64',
            'ink_used_kg'    => 'required|numeric|min:0.001',
            'ink_waste_kg'   => 'nullable|numeric|min:0',
            'notes'          => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'ink_used_kg.min'  => 'Ink used must be at least 0.001 kg.',
            'ink_waste_kg.min' => 'Waste cannot be negative.',
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
