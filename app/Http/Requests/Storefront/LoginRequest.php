<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $email
 * @property string $password
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * max: on both, matching RegisterRequest. Without it this UNAUTHENTICATED
             * endpoint handed whatever it was given straight to the database as a query
             * binding — a 2 MB email was measured going to MySQL, over the 1 MB default
             * max_allowed_packet — and a 2 MB password on to bcrypt. Every other
             * storefront request class already bounds its strings; this one was the gap.
             */
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
