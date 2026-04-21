<?php

namespace App\Http\Requests\ApparelPatternPrice;

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
            'apparel_type_id' => 'sometimes|integer',
            'pattern_type_id' => 'sometimes|integer',
            'price' => 'sometimes|numeric|min:0|max:999999.99',
        ];
    }

    public function messages(): array
    {
        return [
            'apparel_type_id.integer' => 'Apparel type ID must be an integer',
            'pattern_type_id.integer' => 'Pattern type ID must be an integer',
            'price.numeric' => 'Price must be a valid number',
            'price.min' => 'Price must be at least 0',
        ];
    }
}
