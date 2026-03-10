<?php

namespace App\Http\Requests\PatternType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

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
            'name'        => 'required|string|max:50',
            'description' => 'required|string|max:150',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => 'Please enter a name.',
            'name.string'          => 'The name must be valid text.',
            'name.max'             => 'The name cannot exceed 50 characters.',

            'description.required' => 'Please provide a description.',
            'description.string'   => 'The description must be valid text.',
            'description.max'      => 'The description cannot exceed 150 characters.',
        ];
    }
}
