<?php

namespace App\Http\Requests\Pantone;

use Illuminate\Foundation\Http\FormRequest;

class Store extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'hexcolor' => 'required|string|size:7',  // Hex color validation, e.g., #RRGGBB
            'pantone_code' => 'required|string|max:10',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The name field is required.',
            'hexcolor.required' => 'The hex color field is required.',
            'pantone_code.required' => 'The pantone code field is required.',
        ];
    }
}