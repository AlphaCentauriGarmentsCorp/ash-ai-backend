<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class ClientUpdateRequest extends FormRequest
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
            'user_id' => 'sometimes|integer',
            'company_name' => 'sometimes|string|max:255',
            'client_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|max:255',
            'contact' => 'sometimes|string|max:255',
            'street_address' => 'sometimes|string|max:255',
            'city' => 'sometimes|string|max:255',
            'province' => 'sometimes|string|max:255',
            'postal' => 'sometimes|string|max:255',
            'country' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|max:255',
        ];
    }
}
