<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used once, on sign-in, to lift a browser's leftover localStorage cart into the
 * account rather than silently discarding it.
 *
 * @property array $items
 */
class MergeCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:50'],
            // bail + max before exists — see StoreCartItemRequest for why both are needed.
            'items.*.slug' => ['bail', 'required', 'string', 'max:255', 'exists:storefront_products,slug'],
            'items.*.size' => ['required', 'string', 'max:8'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
