<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $email
 */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Deliberately no 'exists:storefront_users,email'. A 422 on an unknown
        // address would answer the question the endpoint's neutral 200 exists
        // to refuse.
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
