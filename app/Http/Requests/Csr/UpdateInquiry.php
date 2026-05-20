<?php

namespace App\Http\Requests\Csr;

use App\Models\Inquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInquiry extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // All fields optional on update — sometimes CSR only flips status,
        // sometimes only updates a link.
        return [
            'client_id'            => ['nullable', 'integer', 'exists:clients,id'],
            'client_name'          => ['sometimes', 'required', 'string', 'max:255'],
            'client_email'         => ['nullable', 'email', 'max:255'],
            'client_contact'       => ['nullable', 'string', 'max:64'],
            'brand_name'           => ['nullable', 'string', 'max:255'],
            'source'               => ['nullable', 'string', 'max:32'],
            'messenger_link'       => ['nullable', 'url', 'max:255'],
            'facebook_link'        => ['nullable', 'url', 'max:255'],
            'gc_link'              => ['nullable', 'url', 'max:255'],
            'product_interest'     => ['nullable', 'string'],
            'status'               => ['nullable', Rule::in(Inquiry::STATUSES)],
            'assigned_csr_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'internal_notes'       => ['nullable', 'string'],
        ];
    }
}
