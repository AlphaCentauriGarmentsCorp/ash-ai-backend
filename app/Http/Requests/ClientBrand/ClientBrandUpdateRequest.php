<?php

namespace App\Http\Requests\ClientBrand;

use Illuminate\Foundation\Http\FormRequest;

class ClientBrandUpdateRequest extends FormRequest
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
            'client_id'  => 'sometimes|integer',
            'brand_name'  => 'sometimes|string',
            'logo_url'  => 'sometimes|string',
            'notes'  => 'sometimes|string',
        ];
    }
}
