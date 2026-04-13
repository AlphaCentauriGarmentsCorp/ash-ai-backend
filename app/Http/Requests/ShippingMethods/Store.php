<?php

namespace App\Http\Requests\ShippingMethods;

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
            'courier_id'  => 'required|exists:courier_list,id',
            'name'        => 'required|string|max:50',
            'description' => 'required|string|max:150',
        ];
    }

    public function messages(): array
    {
        return [
            'courier_id.required' => 'The courier_id field is required.',
            'courier_id.exists'   => 'The selected courier does not exist.',
            'name.required'       => 'The name field is required.',
            'name.string'         => 'The name must be a string.',
            'name.max'            => 'The name may not be greater than 50 characters.',
            'description.required'=> 'The description field is required.',
            'description.string'  => 'The description must be a string.',
            'description.max'     => 'The description may not be greater than 150 characters.',
        ];
    }
}