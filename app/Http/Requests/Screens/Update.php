<?php

namespace App\Http\Requests\Screens;

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
            'name'          => 'sometimes|string|max:255',
            'address'       => 'sometimes|string|max:255',
            'size'          => 'sometimes|string|max:255',
            'mesh_count'    => 'sometimes|string|max:255',
        ];
    }
}
