<?php

namespace App\Http\Requests\Csr;

use App\Models\OrderPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Multipart form request — payment proof image/PDF is optional
 * (a payment record can be created without a proof, status='waiting',
 * then a separate upload step transitions it to 'for_verification').
 */
class UploadPayment extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'           => ['required', 'integer', 'exists:orders,id'],
            'payment_type'       => ['required', Rule::in(OrderPayment::TYPES)],
            'amount'             => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'payment_method_id'  => ['nullable', 'integer', 'exists:payment_methods,id'],
            'reference_number'   => ['nullable', 'string', 'max:128'],
            'payer_name'         => ['nullable', 'string', 'max:128'],
            'paid_at'            => ['nullable', 'date'],
            'notes'              => ['nullable', 'string'],
            'proof'              => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }
}
