<?php

namespace App\Http\Requests\GraphicArtist;

use App\Models\OrderDesignFile;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Phase 5-H — Validates a new design file upload from the Graphic
 * Artist portal.
 *
 * multipart/form-data. Allowlist: png, jpg, jpeg, pdf, psd, svg, webp, ai.
 *
 * Note on .ai files: Adobe Illustrator's native format is detected by
 * Laravel's MIME guesser as application/postscript (legacy) or
 * application/pdf (modern PDF-compatible AI files). The `mimes:ai` rule
 * catches the first case; modern AI files pass the `pdf` rule. Both
 * routes succeed under this allowlist.
 */
class StoreDesignFile extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'        => 'required|integer|exists:orders,id',
            'order_stage_id'  => 'required|integer|exists:order_stages,id',
            'kind'            => ['required', 'string', Rule::in(OrderDesignFile::KINDS)],
            // 25 MB upper limit — accommodates layered PSDs and big PDFs.
            'file'            => 'required|file|mimes:png,jpg,jpeg,pdf,psd,svg,webp,ai|max:25600',
            'notes'           => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'A file is required.',
            'file.mimes'    => 'File type not allowed. Accepted: PNG, JPG, PDF, PSD, SVG, WebP, AI.',
            'file.max'      => 'File must be smaller than 25 MB.',
            'kind.in'       => 'Invalid file kind.',
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