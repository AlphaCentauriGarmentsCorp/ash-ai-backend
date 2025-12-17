<?php

namespace App\Http\Requests\OrdersPayment;

use Illuminate\Foundation\Http\FormRequest;

class OrdersPaymentUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'po_id' => 'required|string|max:50',
            'payment_type' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|max:50',
            'payment_method' => 'required|string|max:50',
            'reference_number' => 'string|max:50',
            'proof' => 'string|max:50',
            'remarks' => 'string|max:50',
            'verified_by' => 'string|max:50',
            'verified_at' => 'string|max:50',   
            'status' => 'string|max:50',
        ];
    }
}
