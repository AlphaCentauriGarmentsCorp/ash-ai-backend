<?php

namespace App\Http\Requests\FabricType;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
            'name.required' => 'The name field is required.',
            'name.max'      => 'The name may not be greater than 50 characters.',
            'description.max' => 'The description may not be greater than 150 characters.',
        ];
    }
}
