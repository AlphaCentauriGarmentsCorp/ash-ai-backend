<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;
use Closure;

class Store extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => 'nullable|integer|exists:clients,id',
            'client_name' => 'required|string|max:255',
            'client_email' => 'nullable|email|max:255',
            'client_facebook' => 'nullable|string|max:255',
            'client_brand' => 'nullable|string|max:255',
            'shirt_color' => 'nullable|string|max:255',
            'apparel_neckline_id' => 'nullable|integer|exists:apparel_necklines,id',
            'free_items' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_price' => 'nullable|numeric|min:0',
            'subtotal' => 'nullable|numeric|min:0',
            'grand_total' => 'nullable|numeric|min:0',

            'item_config_json' => ['required', 'string', $this->jsonStringRule('item_config_json')],
            'items_json' => ['required', 'string', $this->jsonStringRule('items_json')],
            'addons_json' => ['nullable', 'string', $this->jsonStringRule('addons_json')],
            'breakdown_json' => ['nullable', 'string', $this->jsonStringRule('breakdown_json')],
            'print_parts_json' => ['nullable', 'string', $this->jsonStringRule('print_parts_json')],
            'print_parts_files.*' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:4096',
        ];
    }

    private function jsonStringRule(string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field) {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value)) {
                $fail("The {$field} must be a JSON string.");
                return;
            }

            json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $fail("The {$field} field contains malformed JSON.");
            }
        };
    }
}
