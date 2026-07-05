<?php

namespace App\Http\Requests\OrderRoleNote;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Role-directed order notes — validate a new instruction entry.
 *
 * Shape only: presence + length. SEMANTIC validation (is audience_role a
 * real WorkflowStages role? is the body non-blank after trimming?) lives in
 * OrderRoleNoteService so the rules hold for every caller, not just HTTP.
 */
class StoreOrderRoleNote extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audience_role' => 'required|string|max:64',
            'body'          => 'required|string|max:2000',
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
