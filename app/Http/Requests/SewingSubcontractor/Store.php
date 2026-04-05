<?php

namespace App\Http\Requests\SewingSubcontractor;

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
            'name'           => 'required|string|max:255',
            'address'        => 'required|string|max:255',
            'rate_per_pcs'   => 'required|numeric|min:0',
            'contact_number' => 'nullable|string|max:50',
            'email'          => 'nullable|email|max:255',
        ];
    }
}
