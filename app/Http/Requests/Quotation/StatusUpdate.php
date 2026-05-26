<?php

namespace App\Http\Requests\Quotation;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issue 12 — validates a quotation status-transition request.
 *
 * Body:
 *   status  required — must be one of the canonical Quotation::STATUS_* values
 *   notes   optional — free text (e.g. rejection reason)
 *
 * The LEGALITY of the transition (can we go from the current status to this
 * one?) is enforced in QuotationService::changeStatus against the state
 * machine — this request only validates the shape and that the target is a
 * known status.
 */
class StatusUpdate extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Quotation::statuses())],
            'notes'  => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'The status must be one of: ' . implode(', ', Quotation::statuses()) . '.',
        ];
    }
}
