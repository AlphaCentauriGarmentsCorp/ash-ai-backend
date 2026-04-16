<?php

namespace App\Http\Requests\QuotationShare;

use Illuminate\Foundation\Http\FormRequest;

class GenerateTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // permission: view (read-only) | edit (read + update items & print parts)
            'permission'     => ['sometimes', 'string', 'in:view,edit'],

            // allow_download: independent toggle, works on any permission level
            'allow_download' => ['sometimes', 'boolean'],

            'expires_at'     => ['sometimes', 'nullable', 'date', 'after:now'],
            'label'          => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'permission.in'    => 'Permission must be either "view" or "edit".',
            'expires_at.after' => 'Expiry date must be in the future.',
        ];
    }
}
