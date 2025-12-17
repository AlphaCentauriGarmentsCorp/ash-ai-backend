<?php

namespace App\Http\Requests\PoItems;

use Illuminate\Foundation\Http\FormRequest;

class PoItemsUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
            return [
            'po_id'            => 'required|integer|exists:purchase_orders,id',
            'design_code'      => 'required|string|max:255',
            'color'            => 'nullable|string|max:255',
            'size'             => 'required|string|max:50',
            'quantity_ordered' => 'nullable|integer|min:1',
            'variant_code'     => 'nullable|string|max:255',
            'variant_barcode'  => 'nullable|string|max:255',
            'variant_qr_code'  => 'nullable|string|max:255',
        ];

    }
}
