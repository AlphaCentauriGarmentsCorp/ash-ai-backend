<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $email
 * @property string $token
 * @property string $password
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            // Same floor as RegisterRequest, plus 'confirmed' — a typo here locks the
            // account out of the very flow that was meant to recover it.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
