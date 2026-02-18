<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class UserStoreRequest extends FormRequest
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
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'domain_role' => 'nullable|array',
            'domain_role.*' => 'string',
            'domain_access' => 'nullable|array',
            'domain_access.*' => 'string',
        ];
    }
    
    public function messages(): array
    {
        return [];
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
