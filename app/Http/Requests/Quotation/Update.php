<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;
use Closure;
use Illuminate\Validation\Validator;
use App\Models\PrintMethod;

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
            'print_method_id' => 'sometimes|nullable|integer|exists:print_methods,id',
            'special_print' => 'sometimes|nullable|string|max:255',
            'print_area' => 'sometimes|nullable|string|max:255',
            'free_items' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string',
            'custom_pattern_image' => 'sometimes|nullable|string|max:1000',
            'custom_pattern_image_file' => 'sometimes|nullable|file|image|mimes:jpg,jpeg,png,webp|max:4096',

            // ── Issue 7: Brand Label + Care/Size Label spec (see Store.php) ──
            'brand_label_json' => ['sometimes', 'nullable', 'string', $this->jsonStringRule('brand_label_json'), $this->labelSchemaRule('brand_label_json')],
            'care_label_json' => ['sometimes', 'nullable', 'string', $this->jsonStringRule('care_label_json'), $this->labelSchemaRule('care_label_json')],
            'label_design_path' => 'sometimes|nullable|string|max:1000',
            'label_design_file' => 'sometimes|nullable|file|image|mimes:jpg,jpeg,png,webp,svg|max:4096',

            'discount_type' => 'sometimes|nullable|in:percentage,fixed',
            'discount_price' => 'sometimes|nullable|numeric|min:0',
            'subtotal' => 'sometimes|nullable|numeric|min:0',
            'grand_total' => 'sometimes|nullable|numeric|min:0',

            'item_config_json' => ['sometimes', 'string', $this->jsonStringRule('item_config_json')],
            'items_json' => ['sometimes', 'string', $this->jsonStringRule('items_json')],
            'addons_json' => ['sometimes', 'nullable', 'string', $this->jsonStringRule('addons_json')],
            'breakdown_json' => ['sometimes', 'nullable', 'string', $this->jsonStringRule('breakdown_json')],
            'print_parts_json' => ['sometimes', 'nullable', 'string', $this->jsonStringRule('print_parts_json'), $this->printPartsSchemaRule()],
            'print_parts_files.*' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:4096',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $printMethodId = $this->input('print_method_id');
            if (! $printMethodId) {
                return;
            }

            $printMethod = PrintMethod::find($printMethodId);
            if (! $printMethod) {
                return;
            }

            if (strcasecmp(trim((string) $printMethod->name), 'silkscreen') !== 0) {
                return;
            }

            if (! $this->filled('print_area')) {
                $validator->errors()->add('print_area', 'The print_area field is required for silkscreen print method.');
            }
        });
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

    /**
     * Issue 7 — validate a label spec blob (brand_label_json / care_label_json).
     * Mirrors Store::labelSchemaRule(); see that file for the full rationale.
     */
    private function labelSchemaRule(string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field) {
            if ($value === null || $value === '') {
                return;
            }

            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return; // jsonStringRule already reported malformed JSON
            }

            $stringFields = ['material', 'method', 'placement', 'measurement', 'notes'];
            foreach ($stringFields as $key) {
                if (array_key_exists($key, $decoded) && $decoded[$key] !== null && ! is_string($decoded[$key])) {
                    $fail("The {$field}.{$key} field must be a string.");
                }
            }

            $enabled = ! empty($decoded['enabled']);
            if ($enabled) {
                if (empty($decoded['material']) || ! is_string($decoded['material'])) {
                    $fail("The {$field}.material field is required when the label is enabled.");
                }
                if (empty($decoded['placement']) || ! is_string($decoded['placement'])) {
                    $fail("The {$field}.placement field is required when the label is enabled.");
                }
            }
        };
    }

    private function printPartsSchemaRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($value === null || $value === '') {
                return;
            }

            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                return;
            }

            foreach ($decoded as $index => $part) {
                if (! is_array($part)) {
                    $fail("The {$attribute}.{$index} entry must be an object.");
                    continue;
                }

                if (! array_key_exists('part_id', $part)) {
                    $fail("The {$attribute}.{$index}.part_id field is required.");
                }

                if (! array_key_exists('part', $part) || ! is_string($part['part']) || trim($part['part']) === '') {
                    $fail("The {$attribute}.{$index}.part field is required.");
                }

                // Numeric pricing fields validated only IF PRESENT (see Store.php
                // for rationale): different print methods carry different fields,
                // and the pricing engine defaults missing values to 0.
                $optionalNumericFields = [
                    'color_count', 'price_per_color', 'full_color_count', 'price_per_full_color',
                    'unit_count', 'full_unit_count', 'width', 'height', 'pieces',
                ];
                foreach ($optionalNumericFields as $numericField) {
                    if (! array_key_exists($numericField, $part)) {
                        continue; // optional
                    }
                    if (! is_numeric($part[$numericField])) {
                        $fail("The {$attribute}.{$index}.{$numericField} field must be numeric.");
                        continue;
                    }
                    if ((float) $part[$numericField] < 0) {
                        $fail("The {$attribute}.{$index}.{$numericField} field must be greater than or equal to 0.");
                    }
                }

                $imageInputType = $part['image_input_type'] ?? null;
                if (! in_array($imageInputType, ['file', 'link'], true)) {
                    $fail("The {$attribute}.{$index}.image_input_type field must be either file or link.");
                    continue;
                }

                if ($imageInputType === 'link') {
                    $imageLink = $part['image_link'] ?? null;
                    if (! is_string($imageLink) || trim($imageLink) === '') {
                        $fail("The {$attribute}.{$index}.image_link field is required when image_input_type is link.");
                    }
                }
            }
        };
    }
}
