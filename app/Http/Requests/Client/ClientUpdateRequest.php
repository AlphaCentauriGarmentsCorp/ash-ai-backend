<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


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
            'user_id' => 'sometimes|exists:users,id',
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

    public function messages(): array
    {
        return [
            'user_id.exists' => 'Selected user does not exist or is invalid.',
            'user_id.required' => 'User is required.',

            'company_name.required' => 'Company name is required.',
            'company_name.max' => 'Company name must not exceed 255 characters.',

            'client_name.required' => 'Client name is required.',
            'client_name.max' => 'Client name must not exceed 255 characters.',

            'email.required' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.max' => 'Email address must not exceed 255 characters.',

            'contact.required' => 'Contact number is required.',
            'contact.max' => 'Contact number must not exceed 255 characters.',

            'street_address.required' => 'Street address is required.',
            'street_address.max' => 'Street address must not exceed 255 characters.',

            'city.required' => 'City is required.',
            'city.max' => 'City must not exceed 255 characters.',

            'province.required' => 'Province is required.',
            'province.max' => 'Province must not exceed 255 characters.',

            'postal.required' => 'Postal code is required.',
            'postal.max' => 'Postal code must not exceed 255 characters.',

            'country.required' => 'Country is required.',
            'country.max' => 'Country must not exceed 255 characters.',

            'status.required' => 'Status is required.',
            'status.max' => 'Status must not exceed 255 characters.',
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
