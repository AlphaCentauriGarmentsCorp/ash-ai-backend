<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The user being edited (route param {id}), so unique checks ignore self.
        $userId = $this->route('id');

        return [
            // Account
            'username' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            // Password is optional on edit; blank/absent = unchanged.
            'password' => 'nullable|string|min:8',
            'roles' => 'sometimes|array|min:1',
            'roles.*' => 'string',

            // Profile
            'profile' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',

            // Personal Info
            'first_name' => 'sometimes|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'gender' => 'sometimes|in:male,female,other',
            'birthdate' => 'sometimes|date',
            'civil_status' => 'sometimes|string',
            'contact_number' => 'sometimes|string|max:20',

            // Employment
            'position' => 'sometimes|string|max:100',
            'department' => 'sometimes|string|max:100',

            // Government IDs
            'pagibig' => 'nullable|string',
            'sss' => 'nullable|string',
            'philhealth' => 'nullable|string',

            // Current Address
            'currentStreet' => 'nullable|string',
            'currentBarangay' => 'nullable|string',
            'currentCity' => 'nullable|string',
            'currentProvince' => 'nullable|string',
            'currentPostalCode' => 'nullable|string',

            // Permanent Address
            'permanentStreet' => 'nullable|string',
            'permanentBarangay' => 'nullable|string',
            'permanentCity' => 'nullable|string',
            'permanentProvince' => 'nullable|string',
            'permanentPostalCode' => 'nullable|string',

            // Additional Files (newly uploaded files only)
            'additionalFiles' => 'nullable|array',
            'additionalFiles.*' => 'file|max:5120',
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
