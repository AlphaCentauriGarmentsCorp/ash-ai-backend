<?php

namespace App\Http\Requests\Addons;

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
            'category_id' => 'required|exists:addon_categories,id',
            'name' => 'required|string|max:255',
            'price_type' => 'required|in:Paid,Free',
            'price' => 'nullable|numeric|min:0|max:99999999.99',
            'description' => 'required|string',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->price_type === 'Paid' && empty($this->price)) {
                $validator->errors()->add('price', 'The price field is required when price type is Paid.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Please select an addon category.',
            'category_id.exists'   => 'The selected addon category is invalid.',
        ];
    }
}
