<?php

namespace App\Http\Requests\TypePrintingMethod;

use Illuminate\Foundation\Http\FormRequest;

class TypePrintingMethodStoreRequest extends FormRequest
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
            'name' => 'required|string|max:50',
            'description' => 'required|string|max:50',
            'minimum' => 'required|numeric|min:1',
        ];
    }
}
