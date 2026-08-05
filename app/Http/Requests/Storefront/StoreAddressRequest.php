<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string|null $label
 * @property string $name
 * @property string $phone
 * @property string $street
 * @property string|null $barangay
 * @property string $city
 * @property string $province
 * @property string|null $region
 * @property string|null $postal
 * @property bool|null $is_default_shipping
 * @property bool|null $is_default_billing
 */
class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'street' => ['required', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'province' => ['required', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal' => ['nullable', 'string', 'max:10'],
            'is_default_shipping' => ['boolean'],
            'is_default_billing' => ['boolean'],
        ];
    }
}
