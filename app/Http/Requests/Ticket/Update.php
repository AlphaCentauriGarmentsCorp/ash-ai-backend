<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class Update extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_type'  => 'required|string|max:100',
            'quotation_id'  => 'nullable|integer|exists:quotations,id',
            'order_id'      => 'nullable|integer|exists:orders,id',
            'from_role'     => 'required|string|max:100',
            'to_role'       => 'required|string|max:100',
            'message'       => 'required|string',
            'status'        => 'required|string|in:open,pending,resolved,closed',
            'attachments'   => 'nullable|array|max:10',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,gif,pdf,psd|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'request_type.required' => 'The request type field is required.',
            'request_type.string'   => 'The request type must be a string.',
            'request_type.max'      => 'The request type may not be greater than 100 characters.',

            'quotation_id.integer'  => 'The quotation id must be a valid number.',
            'quotation_id.exists'   => 'The selected quotation does not exist.',

            'order_id.integer'      => 'The order id must be a valid number.',
            'order_id.exists'       => 'The selected order does not exist.',

            'from_role.required'    => 'The from role field is required.',
            'from_role.string'      => 'The from role must be a string.',
            'from_role.max'         => 'The from role may not be greater than 100 characters.',

            'to_role.required'      => 'The to role field is required.',
            'to_role.string'        => 'The to role must be a string.',
            'to_role.max'           => 'The to role may not be greater than 100 characters.',

            'message.required'      => 'The message field is required.',
            'message.string'        => 'The message must be a string.',

            'status.required'       => 'The status field is required.',
            'status.string'         => 'The status must be a string.',
            'status.in'             => 'The status must be open, pending, resolved, or closed.',

            'attachments.array'     => 'Attachments must be provided as a list.',
            'attachments.max'       => 'You may upload a maximum of 10 attachments.',
            'attachments.*.file'    => 'Each attachment must be a valid file.',
            'attachments.*.mimes'   => 'Each attachment must be a JPG, PNG, WEBP, GIF, PDF, or PSD file.',
            'attachments.*.max'     => 'Each attachment must not exceed 10MB.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422)
        );
    }
}