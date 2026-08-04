<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property string $email
 * @property string $ship_to_name
 * @property string $phone
 * @property string $street
 * @property string|null $barangay
 * @property string $city
 * @property string $province
 * @property string|null $region
 * @property string|null $postal
 * @property string $shipping_method
 * @property string $payment_method
 * @property array $items
 * @property string|null $discount_code
 * @property string|null $simulate
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Contact + shipping address
            // max:255 matches storefront_orders.email (varchar 255) and the other five
            // storefront request classes. Laravel's 'email' rule imposes no length cap
            // of its own, so without this a 300-character but RFC-valid address passes
            // validation and then trips MySQL strict mode's error 1406 on INSERT — a
            // 500 where the caller should have been told which field was wrong.
            'email' => ['required', 'email', 'max:255'],
            'ship_to_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:30'],
            'street' => ['required', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'province' => ['required', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal' => ['nullable', 'string', 'max:10'],

            // Fulfilment choices
            'shipping_method' => ['required', Rule::in(array_keys(config('reefer.shipping_methods')))],
            'payment_method' => ['required', Rule::in(config('reefer.payment_methods'))],

            // Cart lines. Prices are NEVER trusted from the client — the server
            // re-prices every line from the catalog. Client only sends slug/size/qty.
            // The slug/size pairing and stock are checked in the controller, where
            // the catalog lookup happens.
            'items' => ['required', 'array', 'min:1', 'max:50'],
            // bail + max before exists — see StoreCartItemRequest for why both are needed.
            'items.*.slug' => ['bail', 'required', 'string', 'max:255', 'exists:storefront_products,slug'],
            'items.*.size' => ['required', 'string', 'max:8'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],

            // The code only. What it is worth is decided by the controller from the
            // discounts table, never sent. Existence and usability are checked
            // there too, so a stale code is refused with a reason rather than an
            // "invalid" the shopper cannot act on.
            'discount_code' => ['nullable', 'string', 'max:40'],

            // Optional: exercise the simulated decline path.
            'simulate' => ['nullable', Rule::in(['fail'])],
        ];
    }
}
