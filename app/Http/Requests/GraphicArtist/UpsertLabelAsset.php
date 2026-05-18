<?php

namespace App\Http\Requests\GraphicArtist;

use App\Models\OrderLabelAsset;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Phase 5-H — Validates a label asset upsert.
 *
 * multipart/form-data. Both file and metadata are optional individually
 * — but the row must end up meaningful, which the service handles.
 *
 * Allowlist: png, jpg, jpeg, pdf, psd, svg, webp, ai.
 */
class UpsertLabelAsset extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'         => 'required|integer|exists:orders,id',
            'order_stage_id'   => 'required|integer|exists:order_stages,id',
            'kind'             => ['required', 'string', Rule::in(OrderLabelAsset::KINDS)],
            'file'             => 'nullable|file|mimes:png,jpg,jpeg,pdf,psd,svg,webp,ai|max:10240',

            'width_in'         => 'nullable|numeric|min:0|max:999.99',
            'height_in'        => 'nullable|numeric|min:0|max:999.99',
            'printing_process' => ['nullable', 'string', Rule::in(OrderLabelAsset::PRINTING_PROCESSES)],
            'color_count'      => 'nullable|integer|min:0|max:64',
            'background_color' => 'nullable|string|max:32',
            'material'         => 'nullable|string|max:64',
            'notes'            => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'File type not allowed. Accepted: PNG, JPG, PDF, PSD, SVG, WebP, AI.',
            'file.max'   => 'File must be smaller than 10 MB.',
            'kind.in'    => 'Invalid label kind. Allowed: main_label, size_label, hangtag.',
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