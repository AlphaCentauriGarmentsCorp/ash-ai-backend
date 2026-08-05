<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property string $reason
 * @property string|null $note
 * @property array<int, array<string, mixed>> $items
 */
class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Whether this account may return THIS order depends on the order — owner,
        // stage, delivery window — and on how much of each line is already spoken
        // for, which only makes sense to read inside the controller's transaction.
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(array_keys(config('reefer.returns.reasons')))],
            'note' => ['nullable', 'string', 'max:1000'],

            // Capped to match StoreOrderRequest. Validation runs three rules per
            // element before any ownership or quantity guard does, so an uncapped
            // array is free work an authenticated caller can ask for.
            'items' => ['required', 'array', 'min:1', 'max:50'],
            // Lines are addressed the way the order API hands them out: slug + size.
            // order_items ids are not part of the public order shape, and a qty here
            // is only ever a request — the controller decides what is allowed.
            'items.*.slug' => ['required', 'string', 'max:255'],
            'items.*.size' => ['required', 'string', 'max:8'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Tell us why you are sending it back.',
            'reason.in' => 'Pick one of the listed return reasons.',
            'items.required' => 'Pick at least one item to return.',
            'items.min' => 'Pick at least one item to return.',
            'items.*.qty.min' => 'Return at least one of each item you pick.',
        ];
    }
}
