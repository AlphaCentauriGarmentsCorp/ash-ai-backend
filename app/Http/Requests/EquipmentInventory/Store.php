<?php

namespace App\Http\Requests\EquipmentInventory;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location_id'   => 'required|integer|exists:equipment_location,id',
            'name'          => 'required|string|max:255',
            'quantity'      => 'required|integer|min:0',
            'color'         => 'nullable|string|max:255',
            'model'         => 'nullable|string|max:255',
            'material'      => 'nullable|string|max:255',
            'price'         => 'nullable|string|max:255',
            'penalty'       => 'nullable|string|max:255',
            'design'        => 'nullable|string',
            'description'   => 'nullable|string',
            'image'         => 'nullable|max:25600',
            'receipt'       => 'nullable|array',
            'receipt.*'     => 'nullable|max:25600',
        ];
    }
}
