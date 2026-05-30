<?php

namespace App\Http\Requests\StageUpload;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 3 — validate a stage proof-of-work upload.
 *
 * Accepts common image types plus PDF (some proofs are scanned/exported).
 * Keep the limit generous but bounded (15 MB) since these can be hi-res photos.
 */
class StoreStageUpload extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Validate by EXTENSION rather than `mimes`. The `mimes` rule relies
            // on PHP's fileinfo to sniff the real MIME type, which misdetects /
            // rejects perfectly valid images on some Windows/XAMPP setups
            // (causing a spurious 422). `extensions` checks the client filename
            // extension, and `image`/`file` give a lightweight sanity check
            // without the fileinfo dependency.
            'file'     => 'required|file|extensions:jpeg,jpg,png,webp,gif,pdf|max:15360',
            'category' => 'nullable|string|max:32',
            'notes'    => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'   => 'A file is required.',
            'file.extensions' => 'File must be an image (JPEG, PNG, WebP, GIF) or a PDF.',
            'file.max'        => 'File must be smaller than 15 MB.',
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