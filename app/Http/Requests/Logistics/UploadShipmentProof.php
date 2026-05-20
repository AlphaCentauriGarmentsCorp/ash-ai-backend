<?php

namespace App\Http\Requests\Logistics;

use App\Models\StageSubcontractShipment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Phase 5-I — Upload a single proof file to a shipment.
 *
 * The `kind` parameter routes the file to the matching column:
 *   payment     → payment_proof_path
 *   pickup      → pickup_proof_path
 *   delivery    → delivery_proof_path
 *   signature   → receiver_signature_path
 *   gas_receipt → gas_receipt_path
 *
 * Photo allowlist: JPG / PNG / WebP / PDF / HEIC. 10 MB max.
 */
class UploadShipmentProof extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $kinds = array_keys(StageSubcontractShipment::PROOF_KINDS);

        return [
            'kind' => ['required', Rule::in($kinds)],
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,heic|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'File type not allowed. Accepted: JPG, PNG, WebP, PDF, HEIC.',
            'file.max'   => 'File must be smaller than 10 MB.',
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
