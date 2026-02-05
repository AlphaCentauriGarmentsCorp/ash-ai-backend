<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            // Account
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'roles' => 'required|array',
            'roles.*' => 'string',

            // Profile
            'profile' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',

            // Personal Info
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'gender' => 'required|in:male,female,other',
            'birthdate' => 'required|date',
            'civil_status' => 'required|string',
            'contact_number' => 'required|string|max:20',

            // Employment
            'position' => 'required|string|max:100',
            'department' => 'required|string|max:100',

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

            // Additional Files
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
