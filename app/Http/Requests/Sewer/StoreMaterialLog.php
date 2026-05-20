<?php

namespace App\Http\Requests\Sewer;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreMaterialLog extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'        => 'required|integer|exists:orders,id',
            'order_stage_id'  => 'required|integer|exists:order_stages,id',
            'material_type'   => 'required|in:main_fabric,rib_trim,thread,interfacing,other,waste',
            'fabric_used_kg'  => 'required|numeric|min:0.01',
            'waste_kg'        => 'nullable|numeric|min:0',
            'fabric_roll_id'  => 'nullable|string|max:64',
            'notes'           => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'material_type.in'   => 'Material type must be one of: main_fabric, rib_trim, thread, interfacing, other, waste.',
            'fabric_used_kg.min' => 'Amount used must be at least 0.01.',
            'waste_kg.min'       => 'Waste cannot be negative.',
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
