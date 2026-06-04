<?php

namespace App\Http\Requests\MaterialPrep;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Change 18 — save the Material Prep requirement for an order.
 * Route is gated by portal.material-prep; the service re-checks the
 * stage/role rule when creating the underlying material request.
 */
class SaveMaterialRequirement extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'                      => ['required', 'array', 'min:1'],
            'items.*.material_id'        => ['required', 'integer', 'exists:materials,id'],
            'items.*.quantity_requested' => ['required', 'numeric', 'min:0.01'],
            'items.*.notes'              => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'              => 'Add at least one material to the requirement.',
            'items.*.material_id.required' => 'Pick a catalog material for each line.',
            'items.*.material_id.exists'  => 'One of the selected materials no longer exists.',
            'items.*.quantity_requested.min' => 'Quantity must be greater than zero.',
        ];
    }
}
