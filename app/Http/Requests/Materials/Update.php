<?php

namespace App\Http\Requests\Materials;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
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
     * Mirrors Store: only the material name matters; supplier/type and the
     * rest are optional and may be cleared (nullable).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'supplier_id' => 'sometimes|nullable|exists:suppliers,id',
            'material_type' => 'sometimes|nullable|string|max:100',
            'unit' => 'nullable|string|max:50',
            'price' => 'nullable|numeric|min:0',
            'minimum' => 'nullable|string|min:0',
            'lead' => 'nullable|string|min:0',
            'notes' => 'nullable|string',
        ];
    }
}
