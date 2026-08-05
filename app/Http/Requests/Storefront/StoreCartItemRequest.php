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
            // bail + max BEFORE exists: without both, an oversized slug is sent to the
            // database as a query binding by the exists rule before anything rejects it.
            // bail is the load-bearing half — Laravel runs every rule for an attribute
            // and collects the failures, so max alone would still let exists run.
            'slug' => ['bail', 'required', 'string', 'max:255', 'exists:storefront_products,slug'],
            'size' => ['required', 'string', 'max:8'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }
}
