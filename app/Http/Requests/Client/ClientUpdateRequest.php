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
            'first_name'       => 'sometimes|string|max:255',
            'last_name'        => 'sometimes|string|max:255',
            'email'            => 'sometimes|email|max:255',
            'contact_number'   => 'sometimes|string|min:10|max:15',

            // Address — nullable so a cleared/empty optional field (which the
            // global ConvertEmptyStringsToNull middleware turns into null)
            // passes instead of failing the `string` rule with a 422.
            'street_address'   => 'sometimes|nullable|string|max:255',
            'city'             => 'sometimes|nullable|string|max:255',
            'province'         => 'sometimes|nullable|string|max:255',
            'barangay'         => 'sometimes|nullable|string|max:255',
            'postal_code'      => 'sometimes|nullable|string|max:10',

            'courier'          => 'sometimes|nullable|string|max:255',
            'method'           => 'sometimes|nullable|string|max:255',

            // Optional
            'notes'            => 'sometimes|nullable|string',

            // Brands array
            'brands'           => 'sometimes|array|min:1',
            'brands.*.name'    => 'sometimes|string|max:255',
            'brands.*.logo'    => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:25600',
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
