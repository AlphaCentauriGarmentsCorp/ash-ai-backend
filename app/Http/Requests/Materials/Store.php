<?php

namespace App\Http\Requests\Materials;

use Illuminate\Foundation\Http\FormRequest;

class Store extends FormRequest
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
     * Only the material NAME is required; every other field is optional
     * (mirrors the reworked "Materials & Suppliers" Add form).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'material_type' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:50',
            'price' => 'nullable|numeric|min:0',
            'minimum' => 'nullable|string|min:0',
            'lead' => 'nullable|string|min:0',
            'notes' => 'nullable|string',
        ];
    }
}
