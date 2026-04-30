<?php

namespace App\Http\Requests\QuotationShare;

use Illuminate\Foundation\Http\FormRequest;
use Closure;

/**
 * Validates the public PUT payload.
 *
 * Accepts print parts under either print_parts or print_parts_json.
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
            'print_parts' => ['nullable', $this->jsonOrArrayRule('print_parts'), $this->printPartsSchemaRule('print_parts')],
            'print_parts_json' => ['nullable', $this->jsonOrArrayRule('print_parts_json'), $this->printPartsSchemaRule('print_parts_json')],
            'print_parts_files.*' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:4096',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['print_parts', 'print_parts_json'] as $field) {
            $printParts = $this->input($field);

            if (is_array($printParts) && ! array_is_list($printParts)) {
                $normalized[$field] = array_values($printParts);
            }
        }

        if (! empty($normalized)) {
            $this->merge($normalized);
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

    private function printPartsSchemaRule(string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field) {
            if ($value === null || $value === '') {
                return;
            }

            $parts = $value;
            if (is_string($value)) {
                $parts = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($parts)) {
                    return;
                }
            }

            if (! is_array($parts)) {
                $fail("The {$field} must be an array payload.");
                return;
            }

            foreach ($parts as $index => $part) {
                if (! is_array($part)) {
                    $fail("The {$field}.{$index} entry must be an object.");
                    continue;
                }

                if (! array_key_exists('part_id', $part)) {
                    $fail("The {$field}.{$index}.part_id field is required.");
                }

                if (! array_key_exists('part', $part) || ! is_string($part['part']) || trim($part['part']) === '') {
                    $fail("The {$field}.{$index}.part field is required.");
                }

                foreach (['unit_count', 'price_per_unit', 'full_unit_count', 'price_per_full_unit'] as $numericField) {
                    if (! array_key_exists($numericField, $part) || ! is_numeric($part[$numericField])) {
                        $fail("The {$field}.{$index}.{$numericField} field is required and must be numeric.");
                        continue;
                    }

                    if ((float) $part[$numericField] < 0) {
                        $fail("The {$field}.{$index}.{$numericField} field must be greater than or equal to 0.");
                    }
                }

                $imageInputType = $part['image_input_type'] ?? null;
                if (! in_array($imageInputType, ['file', 'link'], true)) {
                    $fail("The {$field}.{$index}.image_input_type field must be either file or link.");
                    continue;
                }

                if ($imageInputType === 'link') {
                    $imageLink = $part['image_link'] ?? null;
                    if (! is_string($imageLink) || trim($imageLink) === '') {
                        $fail("The {$field}.{$index}.image_link field is required when image_input_type is link.");
                    }
                }
            }
        };
    }

    public function messages(): array
    {
        return [
            'print_parts' => 'The print_parts must be either a JSON string or array payload.',
            'print_parts_json' => 'The print_parts_json must be either a JSON string or array payload.',
            'print_parts_files.*.image' => 'Each print part image must be a valid image file.',
            'print_parts_files.*.max' => 'Each image must not exceed 4MB.',
        ];
    }
}
