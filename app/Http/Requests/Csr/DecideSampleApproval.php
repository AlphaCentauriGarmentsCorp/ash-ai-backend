<?php

namespace App\Http\Requests\Csr;

use App\Models\ClientApproval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Multipart form request — CSR's sample-approval decision.
 *
 * `screenshot` (the client's "approved!" / "please change…" reply) is optional.
 * A reason is required on REJECT so the Graphic Artist knows what to rework —
 * that cross-field rule is enforced in SampleApprovalService::decide(), since
 * it depends on the decision value.
 */
class DecideSampleApproval extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'              => ['required', 'integer', 'exists:orders,id'],
            'decision'              => ['required', Rule::in([
                ClientApproval::STATUS_APPROVED,
                ClientApproval::STATUS_REJECTED,
            ])],
            'client_response_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes'        => ['nullable', 'string', 'max:2000'],
            'screenshot'            => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }
}
