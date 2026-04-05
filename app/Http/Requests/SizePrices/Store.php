<?php

namespace App\Http\Requests\SizePrices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'shirt_id' => [
                'required',
                'exists:tshirt_types,id',
                Rule::unique('size_prices')->where(function ($query) {
                    return $query->where('size_id', request('size_id'));
                }),
            ],
            'size_id' => [
                'required',
                'exists:tshirt_sizes,id',
            ],
            'price' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'shirt_id.unique' => 'This T-shirt type and size combination already exists.',
        ];
    }
}
