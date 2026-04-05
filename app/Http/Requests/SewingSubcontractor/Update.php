<?php

namespace App\Http\Requests\SewingSubcontractor;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'           => 'sometimes|required|string|max:255',
            'address'        => 'sometimes|required|string|max:255',
            'rate_per_pcs'   => 'sometimes|required|numeric|min:0',
            'contact_number' => 'nullable|string|max:50',
            'email'          => 'nullable|email|max:255',
        ];
    }
}
