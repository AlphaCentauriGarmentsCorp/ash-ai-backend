<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class ClientStoreRequest extends FormRequest
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
            'first_name'       => 'required|string|max:255',
            'last_name'        => 'required|string|max:255',
            'email'            => 'required|email|max:255',
            'contact_number'   => 'required|string|min:10|max:15',

            // Address
            'street_address'   => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:255',
            'province'         => 'nullable|string|max:255',
            'barangay'         => 'nullable|string|max:255',
            'postal_code'      => 'nullable|string|max:10',

            'courier'          => 'nullable|string|max:255',
            'method'           => 'nullable|string|max:255',

            // Optional
            'notes'            => 'nullable|string',

            // Brands array
            'brands'           => 'required|array|min:1',
            'brands.*.name'    => 'required|string|max:255',
            'brands.*.logo'    => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'brands.required'        => 'At least one brand is required.',
            'brands.*.name.required' => 'Brand name is required.',
            'brands.*.logo.required' => 'Brand logo is required.',
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
