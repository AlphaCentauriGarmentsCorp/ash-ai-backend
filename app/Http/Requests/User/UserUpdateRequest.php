<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdateRequest extends FormRequest
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
            //
            'name' => 'sometimes|string',
            'email' => 'sometimes|email|unique:users,email',
            'password' => 'sometimes|string|min:6',
            'avatar'=> 'sometimes|string',
            'domain_role' => 'sometimes|array',
            'domain_role.*' => 'string',
            'domain_access' => 'sometimes|array',
            'domain_access.*' => 'string',
        ];
    }
}
