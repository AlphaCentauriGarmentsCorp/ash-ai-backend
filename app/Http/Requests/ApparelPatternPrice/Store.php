<?php

namespace App\Http\Requests\ApparelPatternPrice;

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
            'apparel_type_id' => 'required|integer',
            'pattern_type_id' => 'required|integer',
            'price' => 'required|numeric|min:0|max:999999.99',
        ];
    }

    public function messages(): array
    {
        return [
            'apparel_type_id.required' => 'Apparel type ID is required',
            'apparel_type_id.integer' => 'Apparel type ID must be an integer',
            'pattern_type_id.required' => 'Pattern type ID is required',
            'pattern_type_id.integer' => 'Pattern type ID must be an integer',
            'price.required' => 'Price is required',
            'price.numeric' => 'Price must be a valid number',
            'price.min' => 'Price must be at least 0',
        ];
    }
}
