<?php

namespace App\Http\Requests\QuotationShare;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the public PUT payload.
 *
 * Only items_json (name + quantity) and print_parts_json are accepted.
 * Prices and all sensitive fields are explicitly excluded.
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
            // ── Items — add / remove / edit by index, no price ───────────────
            'items_json'              => ['sometimes', 'array', 'min:1'],
            'items_json.*.name'       => ['required_with:items_json', 'string', 'max:255'],
            'items_json.*.quantity'   => ['required_with:items_json', 'numeric', 'min:1'],

            // ── Print parts — full edit including image upload ────────────────
            'print_parts_json'                    => ['sometimes', 'nullable', 'array'],
            'print_parts_json.*.part'             => ['required_with:print_parts_json', 'string', 'in:Front,Back,Sleeves'],
            'print_parts_json.*.color_count'      => ['required_with:print_parts_json', 'integer', 'min:1', 'max:20'],
            'print_parts_json.*.image'            => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'print_parts_json.*.existing_image'   => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'items_json.min'                      => 'At least one item is required.',
            'items_json.*.name.required_with'     => 'Each item must have a name.',
            'items_json.*.quantity.required_with' => 'Each item must have a quantity.',
            'print_parts_json.*.part.in'          => 'Print part must be Front, Back, or Sleeves.',
            'print_parts_json.*.color_count.max'  => 'Color count cannot exceed 20.',
            'print_parts_json.*.image.image'      => 'Each print part image must be a valid image file.',
            'print_parts_json.*.image.max'        => 'Each image must not exceed 4MB.',
        ];
    }
}
