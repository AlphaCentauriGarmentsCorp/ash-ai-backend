<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $password
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:storefront_users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            // max as well as min: an unbounded password is handed to bcrypt as-is, and
            // LoginRequest bounds it identically so the two can never disagree about
            // what is acceptable.
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }
}
