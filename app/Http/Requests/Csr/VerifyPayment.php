<?php

namespace App\Http\Requests\Csr;

use App\Models\OrderPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyPayment extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in([OrderPayment::STATUS_VERIFIED, OrderPayment::STATUS_REJECTED]),
            ],
            // Required only when decision === 'rejected' — service double-checks.
            'rejection_reason' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000'],
        ];
    }
}
