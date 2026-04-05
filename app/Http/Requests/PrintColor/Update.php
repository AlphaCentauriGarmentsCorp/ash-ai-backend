<?php

namespace App\Http\Requests\PrintColor;

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
            'type_id'       => 'required|exists:print_types,id',
            'color_count'   => 'required|numeric|min:0',
            'price'         => 'required|numeric|min:0',
        ];
    }
}
