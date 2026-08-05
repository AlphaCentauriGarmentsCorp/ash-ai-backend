<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Same shape as StoreAddressRequest, but every field is optional so a PATCH can
 * carry only what changed. Ownership is enforced in the controller, not here.
 */
class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'street' => ['sometimes', 'required', 'string', 'max:255'],
            'barangay' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'required', 'string', 'max:120'],
            'province' => ['sometimes', 'required', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal' => ['sometimes', 'nullable', 'string', 'max:10'],
            'is_default_shipping' => ['sometimes', 'boolean'],
            'is_default_billing' => ['sometimes', 'boolean'],
        ];
    }
}
