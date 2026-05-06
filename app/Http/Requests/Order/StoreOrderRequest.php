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
            // Traceability
            'quotation_id'        => 'nullable|integer|exists:quotations,id',

            // Client
            'client_id'           => 'nullable|integer|exists:clients,id',
            'client_name'         => 'nullable|string|max:255',
            'client_brand'        => 'nullable|string|max:255',

            // Apparel config IDs
            'apparel_type_id'     => 'nullable|integer',
            'pattern_type_id'     => 'nullable|integer',
            'apparel_neckline_id' => 'nullable|integer|exists:apparel_necklines,id',
            'print_method_id'     => 'nullable|integer|exists:print_methods,id',

            // Shirt / Print details
            'shirt_color'         => 'nullable|string|max:255',
            'special_print'       => 'nullable|string|max:255',
            'print_area'          => 'nullable|string|max:100',
            'free_items'          => 'nullable|string|max:255',
            'notes'               => 'nullable|string',

            // Pricing
            'discount_type'       => 'nullable|in:percentage,fixed',
            'discount_price'      => 'nullable|numeric|min:0',
            'discount_amount'     => 'nullable|numeric|min:0',
            'subtotal'            => 'nullable|numeric|min:0',
            'grand_total'         => 'nullable|numeric|min:0',

            // JSON blobs
            'item_config_json'    => 'nullable|string',
            'items_json'          => 'nullable|string',
            'addons_json'         => 'nullable|string',
            'breakdown_json'      => 'nullable|string',
            'print_parts_json'    => 'nullable|string',
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
                'errors'  => $errors,
            ], 422)
        );
    }
}