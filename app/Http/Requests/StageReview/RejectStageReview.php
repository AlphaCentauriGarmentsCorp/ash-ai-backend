<?php

namespace App\Http\Requests\StageReview;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * CSR Review Hub — validate a reject action.
 *
 * Comment is REQUIRED (the spec: "on reject, attach a comment (required) plus
 * an optional image"). Image is an optional file on the same shape as the
 * stage_reject_logs photo. Route-level permission gating (access.production-review)
 * handles authorization; this just validates the payload.
 */
class RejectStageReview extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => 'required|string|min:1|max:2000',
            'image'   => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'comment.required' => 'A comment is required when rejecting a stage.',
            'image.mimes'      => 'Image must be a JPEG, PNG, or WebP file.',
            'image.max'        => 'Image must be smaller than 5 MB.',
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
