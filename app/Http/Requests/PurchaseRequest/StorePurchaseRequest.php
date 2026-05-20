<?php

namespace App\Http\Requests\PurchaseRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 3 — Validates ad-hoc PR creation (manager creates a PR
 * directly, without an originating MR). The auto-trigger flow goes
 * through PurchaseRequestService::createFromMaterialRequest instead
 * and doesn't use this form request.
 *
 * Expected shape:
 * {
 *   "order_id": 7,
 *   "supplier_id": 4,
 *   "reason": "Top-up stock for upcoming run",
 *   "items": [
 *     { "material_id": 12, "quantity": 50, "unit_price": 12.50 }
 *   ]
 * }
 */
class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'    => 'required|integer|exists:orders,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'reason'      => 'nullable|string|max:1000',
            'items'                 => 'required|array|min:1',
            'items.*.material_id'   => 'required|integer|exists:materials,id',
            'items.*.quantity'      => 'required|numeric|min:0.01',
            'items.*.unit_price'    => 'nullable|numeric|min:0',
            'items.*.notes'         => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'At least one item is required.',
            'items.min'      => 'At least one item is required.',
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
