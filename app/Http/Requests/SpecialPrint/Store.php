<?php

namespace App\Http\Requests\SpecialPrint;

use Illuminate\Foundation\Http\FormRequest;

class Store extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:50',
            'description' => 'nullable|string|max:150',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'The name field is required.',
            'name.string'        => 'The name must be a string.',
            'name.max'           => 'The name may not be greater than 50 characters.',
            'description.string' => 'The description must be a string.',
            'description.max'    => 'The description may not be greater than 150 characters.',
        ];
    }
}
