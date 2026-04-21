<?php

namespace App\Http\Requests\QuotationShare;

use Illuminate\Foundation\Http\FormRequest;
use Closure;

/**
 * Validates the public PUT payload.
 *
 * Only print_parts_json is accepted for public updates.
 */
class PublicUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Token authorization is handled in the controller
    }

    public function rules(): array
    {
        return [
            'print_parts_json' => ['nullable', $this->jsonOrArrayRule('print_parts_json')],
            'print_parts_files.*' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:4096',
        ];
    }

    protected function prepareForValidation(): void
    {
        $printParts = $this->input('print_parts_json');

        if (is_array($printParts) && ! array_is_list($printParts)) {
            $this->merge([
                'print_parts_json' => array_values($printParts),
            ]);
        }
    }

    private function jsonOrArrayRule(string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field) {
            if ($value === null || $value === '') {
                return;
            }

            if (is_array($value)) {
                return;
            }

            if (! is_string($value)) {
                $fail("The {$field} must be either a JSON string or array payload.");
                return;
            }

            json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $fail("The {$field} field contains malformed JSON.");
            }
        };
    }

    public function messages(): array
    {
        return [
            'print_parts_json' => 'The print_parts_json must be either a JSON string or array payload.',
            'print_parts_files.*.image' => 'Each print part image must be a valid image file.',
            'print_parts_files.*.max' => 'Each image must not exceed 4MB.',
        ];
    }
}
