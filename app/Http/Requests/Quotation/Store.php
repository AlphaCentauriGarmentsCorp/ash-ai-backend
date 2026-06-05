<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;
use Closure;
use Illuminate\Validation\Validator;
use App\Models\PrintMethod;

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
            'apparel_type_id' => 'nullable|integer',
            'pattern_type_id' => 'nullable|integer',
            'shirt_color' => 'nullable|string|max:255',
            'apparel_neckline_id' => 'nullable|integer|exists:apparel_necklines,id',
            'print_method_id' => 'nullable|integer|exists:print_methods,id',
            'special_print' => 'nullable|string|max:255',
            'print_area' => 'nullable|string|max:255',
            'free_items' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            // Custom-pattern reference (Issue 6): a link/path string, or an
            // uploaded file via custom_pattern_image_file.
            'custom_pattern_image' => 'nullable|string|max:1000',
            'custom_pattern_image_file' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:4096',

            // ── Issue 7: Brand Label + Care/Size Label spec ──────────────────
            // Each label is a JSON blob (enabled/material/method/placement/...).
            // The shared label-design artwork is either a link/path string OR an
            // uploaded file via label_design_file (mirrors custom_pattern_image).
            // Each label's internal shape is validated in labelSchemaRule().
            'brand_label_json' => ['nullable', 'string', $this->jsonStringRule('brand_label_json'), $this->labelSchemaRule('brand_label_json')],
            'care_label_json' => ['nullable', 'string', $this->jsonStringRule('care_label_json'), $this->labelSchemaRule('care_label_json')],
            'label_design_path' => 'nullable|string|max:1000',
            'label_design_file' => 'nullable|file|image|mimes:jpg,jpeg,png,webp,svg|max:4096',

            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_price' => 'nullable|numeric|min:0',
            'subtotal' => 'nullable|numeric|min:0',
            'grand_total' => 'nullable|numeric|min:0',

            'item_config_json' => ['required', 'string', $this->jsonStringRule('item_config_json')],
            'items_json' => ['required', 'string', $this->jsonStringRule('items_json')],
            'addons_json' => ['nullable', 'string', $this->jsonStringRule('addons_json')],
            'breakdown_json' => ['nullable', 'string', $this->jsonStringRule('breakdown_json')],
            'print_parts_json' => ['nullable', 'string', $this->jsonStringRule('print_parts_json'), $this->printPartsSchemaRule()],
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
     *
     * The label is optional; when present it must be a JSON object. We only
     * enforce types/lengths on the fields we recognise, and require material +
     * placement IF the label is marked enabled (a CSR who toggles a label on
     * should pick what it is). Method is intentionally lenient: it can be
     * "None" (from the size_labels dropdown), so an enabled label with method
     * "None" is valid. Unknown keys are ignored, keeping the rule forgiving as
     * the dropdown lists evolve in Drop Down Settings.
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

                // Numeric pricing fields are validated only IF PRESENT (must be
                // numeric and >= 0). They are no longer hard-required, because
                // different print methods carry different fields: silkscreen uses
                // color/unit counts; DTF uses width/height/pieces; embroidery and
                // sublimation may carry none (priced via item_config). The pricing
                // engine safely defaults any missing value to 0.
                $optionalNumericFields = [
                    'color_count', 'price_per_color', 'full_color_count', 'price_per_full_color',
                    'unit_count', 'full_unit_count', 'num_colors', 'width', 'height', 'pieces',
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
