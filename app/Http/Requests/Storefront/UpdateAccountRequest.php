<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $birth_date
 * @property string|null $gender
 * @property string|null $email
 * @property string|null $password
 * @property string|null $current_password
 */
class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Every field optional: this is a PATCH, so send only what changed.
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:40'],

            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                // Ignore this user's own row, or "change email" to the same address
                // would collide with itself.
                Rule::unique('storefront_users', 'email')->ignore($this->user()?->id),
            ],

            'password' => ['sometimes', 'required', 'string', 'min:8'],

            // Changing either credential requires proving you know the current one,
            // so a stolen token cannot quietly take the account over.
            'current_password' => [
                Rule::requiredIf(fn () => $this->has('email') || $this->has('password')),
                'string',
                'current_password',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Your current password is incorrect.',
            'current_password.required' => 'Enter your current password to change your email or password.',
        ];
    }
}
