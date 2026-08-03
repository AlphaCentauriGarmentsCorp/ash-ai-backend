<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @property int $rating
 * @property string|null $body
 */
class StoreProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Whether this account may review THIS product depends on its order history,
        // which needs the resolved product — so the check lives in the controller
        // (assertPurchased), not here.
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rating.required' => 'Pick a rating from 1 to 5 stars.',
            'rating.min' => 'Pick a rating from 1 to 5 stars.',
            'rating.max' => 'Pick a rating from 1 to 5 stars.',
        ];
    }
}
