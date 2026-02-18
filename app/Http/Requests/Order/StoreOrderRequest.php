<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Client & Order Info
            'client' => 'required|exists:clients,id',
            'company' => 'required|string|max:255',
            'brand' => 'required|string|max:255',
            'priority' => 'required|string',
            'deadline' => 'required|date',

            // Shipping
            'courier' => 'required|string',
            'method' => 'required|string',
            'receiver_name' => 'required|string',
            'contact_number' => 'required|string',
            'street_address' => 'nullable|string',
            'barangay_address' => 'nullable|string',
            'city_address' => 'nullable|string',
            'province_address' => 'nullable|string',
            'postal_address' => 'nullable|string',

            // Design & Apparel
            'design_name' => 'required|string',
            'apparel_type' => 'required|string',
            'pattern_type' => 'required|string',
            'service_type' => 'required|string',
            'print_method' => 'required|string',
            'print_service' => 'required|string',
            'size_label' => 'required|string',
            'print_label_placement' => 'required|string',

            // Fabric
            'fabric_type' => 'required|string',
            'fabric_supplier' => 'required|string',
            'fabric_color' => 'required|string',
            'thread_color' => 'required|string',
            'ribbing_color' => 'required|string',


            'placement_measurements' => 'nullable|string',
            'notes' => 'nullable|string',

            'freebie_items' => 'nullable|string',
            'freebie_others' => 'nullable|string',
            'freebie_color' => 'nullable|string',


            // Payment
            'payment_method' => 'nullable|string',
            'payment_plan' => 'nullable|string',
            'deposit_percentage' => 'nullable|numeric',

            // Totals
            'total_quantity' => 'required|integer',
            'total_amount' => 'required|numeric',
            'average_unit_price' => 'required|numeric',

            // Files
            'design_files.*' => 'file|mimes:pdf,jpg,png',
            'design_mockup.*' => 'file|mimes:pdf,jpg,png',
            'size_label_files.*' => 'file|mimes:pdf,jpg,png',
            'freebies_files.*' => 'file|mimes:pdf,jpg,png',
            'payments.*' => 'file|mimes:pdf,jpg,png',

            // Arrays
            'sizes' => 'required|array|min:1',
            'sizes.*.name' => 'required|string',
            'sizes.*.costPrice' => 'required|integer',
            'sizes.*.quantity' => 'required|numeric',

            'selectedOptions' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = [];

        foreach ($validator->errors()->toArray() as $field => $messages) {
            $cleanField = preg_replace('/\.\d+$/', '', $field);
            $errors[$cleanField] = $messages[0];
        }

        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors' => $errors,
            ], 422)
        );
    }
}
