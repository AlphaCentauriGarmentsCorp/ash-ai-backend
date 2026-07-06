<?php

namespace App\Http\Requests\GraphicArtist;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * GA Portal CP1 — validates a custom-color find-or-create from the
 * Graphic Artist portal color picker.
 *
 * hexcolor is the only hard requirement (it is the de-dup key). It may
 * arrive with or without a leading '#' and in 3- or 6-digit form; the
 * service normalises it. name is optional — when blank the service
 * auto-names the color to its normalised hex. pantone_code is optional
 * (custom colors usually have none).
 */
class StoreCustomColor extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $hex = $this->input('hexcolor');
        if (is_string($hex)) {
            $this->merge(['hexcolor' => trim($hex)]);
        }
    }

    public function rules(): array
    {
        return [
            'name'         => 'nullable|string|max:120',
            'hexcolor'     => ['required', 'string', 'regex:/^#?(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'pantone_code' => 'nullable|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'hexcolor.required' => 'A hex color is required.',
            'hexcolor.regex'    => 'Hex color must look like #FF0000 or #F00.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors()->toArray(),
            ], 422),
        );
    }
}
