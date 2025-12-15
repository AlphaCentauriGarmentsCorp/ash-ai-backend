<?php

namespace App\Http\Requests\WarehouseMaterial;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseMaterialStoreRequest extends FormRequest
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
            'material_name' => 'required|string|max:50',
            'brand' => 'required|string|max:50',
            'category' => 'required|string|max:50',
            'type' => 'required|string|max:50',
            'unit' => 'required|string|max:50',
            'quantity' => 'required|numeric|min:0',
            'cost_per_unit' => 'required|numeric|min:0',
        ];
    }
}
