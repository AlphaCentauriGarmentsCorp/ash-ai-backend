<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'domain_role' => 'required|array',
            'domain_role.*' => 'string',
            'domain_access' => 'required|array',
            'domain_access.*' => 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Full name is required.',
            'name.string' => 'Full name must be a valid text.',

            'email.required' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',

            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 6 characters long.',

            'domain_role.required' => 'At least one role is required.',
            'domain_role.array' => 'Domain role must be an array.',
            'domain_role.*.string' => 'Each role must be a valid string.',

            'domain_access.required' => 'At least one domain access is required.',
            'domain_access.array' => 'Domain access must be an array.',
            'domain_access.*.string' => 'Each domain access must be a valid string.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = [];

        foreach ($validator->errors()->toArray() as $field => $messages) {
            $cleanField = preg_replace('/\.\d+$/', '', $field);
            $errors[$cleanField] = $messages[0];
        }

        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors' => $errors,
            ], 422)
        );
    }
}
