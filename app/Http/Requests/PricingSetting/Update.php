<?php

namespace App\Http\Requests\PricingSetting;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
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
            'value' => 'required|numeric|min:0|max:9999999999.99',
        ];
    }

    public function messages(): array
    {
        return [
            'value.required' => 'The value field is required.',
            'value.numeric' => 'The value must be a valid number.',
            'value.min' => 'The value must be at least 0.',
            'value.max' => 'The value is too large.',
        ];
    }
}
