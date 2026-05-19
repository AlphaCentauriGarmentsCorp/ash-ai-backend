<?php

namespace App\Http\Requests\Csr;

use App\Models\ClientApproval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Handles BOTH the create-new-approval-request payload AND the
 * respond-to-existing-approval payload. The controller dispatches
 * to ClientApprovalService::record() or respond() based on which
 * endpoint was hit; this request just covers the union of fields.
 *
 * Validators:
 *   - kind is required only when creating (no approval id present)
 *   - decision is required only when responding
 */
class RecordApproval extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isResponding = $this->routeIs('csr.approvals.respond')
            || str_contains((string) $this->route()?->getActionName(), 'respond');

        if ($isResponding) {
            return [
                'decision' => [
                    'required',
                    Rule::in([
                        ClientApproval::STATUS_APPROVED,
                        ClientApproval::STATUS_REVISION_REQUESTED,
                        ClientApproval::STATUS_REJECTED,
                    ]),
                ],
                'client_response_notes' => ['nullable', 'string'],
                'internal_notes'        => ['nullable', 'string'],
                'screenshot'            => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            ];
        }

        // Create-request path
        return [
            'order_id'       => ['required', 'integer', 'exists:orders,id'],
            'kind'           => ['required', Rule::in(ClientApproval::KINDS)],
            'internal_notes' => ['nullable', 'string'],
            'screenshot'     => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }
}
