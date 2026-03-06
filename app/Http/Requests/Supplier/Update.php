<?php

namespace App\Http\Requests\Supplier;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'              => 'sometimes|string|max:255',
            'contact_person'    => 'sometimes|string|max:255',
            'contact_number'    => 'sometimes|string|max:255',
            'email'             => 'nullable|string|max:255',
            'street_address'    => 'nullable|string|max:255',
            'barangay'          => 'nullable|string|max:255',
            'city'              => 'nullable|string|max:255',
            'province'          => 'nullable|string|max:255',
            'postal_code'       => 'nullable|string|max:255',
            'notes'             => 'nullable|string|max:255',
        ];
    }
}
