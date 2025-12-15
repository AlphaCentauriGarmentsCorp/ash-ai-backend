<?php

namespace App\Http\Requests\WarehouseMaterial;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseMaterialUpdateRequest extends FormRequest
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
            'material_name' => 'string|max:50',
            'brand' => 'string|max:50',
            'category' => 'string|max:50',
            'type' => 'string|max:50',
            'unit' => 'string|max:50',
            'quantity' => 'numeric|min:0', 
            'cost_per_unit' => 'numeric|min:0',
        ];
    }
}
