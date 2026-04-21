<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;
use Closure;

class Update extends FormRequest
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
            'client_id' => 'sometimes|nullable|integer|exists:clients,id',
            'client_name' => 'sometimes|required|string|max:255',
            'client_email' => 'sometimes|nullable|email|max:255',
            'client_facebook' => 'sometimes|nullable|string|max:255',
            'client_brand' => 'sometimes|nullable|string|max:255',
            'shirt_color' => 'sometimes|nullable|string|max:255',
            'apparel_neckline_id' => 'sometimes|nullable|integer|exists:apparel_necklines,id',
            'free_items' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string',
            'discount_type' => 'sometimes|nullable|in:percentage,fixed',
            'discount_price' => 'sometimes|nullable|numeric|min:0',
            'subtotal' => 'sometimes|nullable|numeric|min:0',
            'grand_total' => 'sometimes|nullable|numeric|min:0',

            'item_config_json' => ['sometimes', 'string', $this->jsonStringRule('item_config_json')],
            'items_json' => ['sometimes', 'string', $this->jsonStringRule('items_json')],
            'addons_json' => ['sometimes', 'nullable', 'string', $this->jsonStringRule('addons_json')],
            'breakdown_json' => ['sometimes', 'nullable', 'string', $this->jsonStringRule('breakdown_json')],
            'print_parts_json' => ['sometimes', 'nullable', 'string', $this->jsonStringRule('print_parts_json')],
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
