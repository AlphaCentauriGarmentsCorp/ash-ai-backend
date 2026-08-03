<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $slug
 * @property string $size
 * @property int|null $qty
 */
class StoreCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Only slug/size/qty. Price is never accepted from the client — the
            // catalog decides it. The slug/size pairing and stock are checked in
            // the controller, where the lookup happens.
            'slug' => ['required', 'string', 'exists:storefront_products,slug'],
            'size' => ['required', 'string', 'max:8'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }
}
