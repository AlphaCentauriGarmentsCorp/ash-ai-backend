<?php

namespace App\Http\Requests\SizePrices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $id = $this->route('id');

        return [
            'shirt_id' => [
                'required',
                'exists:tshirt_types,id',
                Rule::unique('size_prices')
                    ->where(
                        fn($query) =>
                        $query->where('size_id', $this->size_id)
                    )
                    ->ignore($id),
            ],
            'size_id' => [
                'required',
                'exists:tshirt_sizes,id',
            ],
            'price' => [
                'required',
                'numeric',
                'min:0',
            ],
        ];
    }
}
