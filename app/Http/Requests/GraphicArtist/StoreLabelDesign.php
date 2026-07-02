<?php

namespace App\Http\Requests\GraphicArtist;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * GA Portal CP7 — Validates the shared Label Design upload from the
 * Graphic Artist portal. One file covers both the Brand Label and the
 * Care/Size Label (same convention as Add Order's label_design_file).
 */
class StoreLabelDesign extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'       => 'required|integer|exists:orders,id',
            'order_stage_id' => 'required|integer|exists:order_stages,id',
            'file'           => 'required|file|mimes:png,jpg,jpeg,webp,pdf,svg|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'A label design file is required.',
            'file.mimes'    => 'File type not allowed. Accepted: PNG, JPG, WebP, PDF, SVG.',
            'file.max'      => 'File must be smaller than 10 MB.',
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
