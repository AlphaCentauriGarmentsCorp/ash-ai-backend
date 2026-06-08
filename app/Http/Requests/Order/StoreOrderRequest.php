<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * StoreOrderRequest — validates new-order create payloads.
 *
 * The orders table has a quotation-derived schema (see Order model).
 * This validator accepts BOTH the modern field names that the convert-
 * from-quotation flow sends (`client_id`, `apparel_type_id`, `subtotal`,
 * `grand_total`, ...) AND legacy field names that the older
 * `/orders/new` form might still emit (`client`, `apparel_type` string,
 * `total_amount`, ...). The OrderService normalises whichever shape
 * arrives.
 *
 * Most fields are nullable since the form is partial — the only
 * universally required field is the client identifier (one of `client`
 * or `client_id`).
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Client (one of these is required)
            'client'    => 'required_without:client_id|nullable|exists:clients,id',
            'client_id' => 'required_without:client|nullable|exists:clients,id',

            // Brand / company / client name — accepted in either shape
            'company'      => 'nullable|string|max:255',
            'client_brand' => 'nullable|string|max:255',
            'client_name'  => 'nullable|string|max:255',
            'brand'        => 'nullable|string|max:255',

            // Linkage to source quotation
            'quotation_id' => 'nullable|integer',

            // Apparel + print method (FK ids preferred, strings tolerated)
            'apparel_type'    => 'nullable|string',
            'apparel_type_id' => 'nullable|integer',
            'pattern_type'    => 'nullable|string',
            'pattern_type_id' => 'nullable|integer',
            'print_method'    => 'nullable|string',
            'print_method_id' => 'nullable|integer',
            'apparel_neckline_id' => 'nullable|integer',

            // Print details
            'shirt_color'   => 'nullable|string|max:255',
            'special_print' => 'nullable|string|max:255',
            'print_area'    => 'nullable|string|max:255',
            'free_items'    => 'nullable|string|max:255',
            'notes'         => 'nullable|string',

            // Financials — accept multiple aliases
            'discount_type'   => 'nullable|in:percentage,fixed',
            'discount_price'  => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'subtotal'        => 'nullable|numeric|min:0',
            'grand_total'     => 'nullable|numeric|min:0',
            'estimated_total' => 'nullable|numeric|min:0',
            'total_amount'    => 'nullable|numeric|min:0',
            'total_quantity'     => 'nullable|integer',
            'average_unit_price' => 'nullable|numeric|min:0',

            // JSON blobs — accept arrays OR JSON strings (FormData ships strings)
            'item_config_json' => 'nullable',
            'items_json'       => 'nullable',
            'addons_json'      => 'nullable',
            'breakdown_json'   => 'nullable',
            'print_parts_json' => 'nullable',

            // Form-only fields the service uses for PoItems / Samples
            'sizes'   => 'nullable',
            'samples' => 'nullable',
            'selectedOptions' => 'nullable',

            // Change 11 — superadmin override. The role gate is enforced in
            // OrdersController; here we only shape the inputs. `incomplete_fields`
            // is the list of SOFT-required fields the superadmin chose to skip.
            'override_incomplete' => 'nullable|boolean',
            'incomplete_fields'   => 'nullable|array',
            'incomplete_fields.*' => 'string|max:64',

            // Legacy form fields — accepted but not stored on Order
            'priority' => 'nullable|string',
            'deadline' => 'nullable|date',
            'courier'  => 'nullable|string',
            'method'   => 'nullable|string',
            'receiver_name'    => 'nullable|string',
            'contact_number'   => 'nullable|string',
            'street_address'   => 'nullable|string',
            'barangay_address' => 'nullable|string',
            'city_address'     => 'nullable|string',
            'province_address' => 'nullable|string',
            'postal_address'   => 'nullable|string',
            'design_name'      => 'nullable|string',
            'service_type'     => 'nullable|string',
            'print_service'    => 'nullable|string',
            'size_label'       => 'nullable|string',
            'print_label_placement' => 'nullable|string',
            'fabric_type'      => 'nullable|string',
            'fabric_supplier'  => 'nullable|string',
            'fabric_color'     => 'nullable|string',
            'thread_color'     => 'nullable|string',
            'ribbing_color'    => 'nullable|string',
            'placement_measurements' => 'nullable|string',
            'freebie_items'    => 'nullable|string',
            'freebie_others'   => 'nullable|string',
            'freebie_color'    => 'nullable|string',
            'payment_method'   => 'nullable|string',
            'payment_plan'     => 'nullable|string',
            'deposit_percentage' => 'nullable|numeric',

            // Files (still accepted; service stores them on disk only)
            'design_files.*'     => 'file|mimes:pdf,jpg,jpeg,png',
            'design_mockup.*'    => 'file|mimes:pdf,jpg,jpeg,png',
            'size_label_files.*' => 'file|mimes:pdf,jpg,jpeg,png',
            'freebies_files.*'   => 'file|mimes:pdf,jpg,jpeg,png',
            'payments.*'         => 'file|mimes:pdf,jpg,jpeg,png',
        ];
    }

    /**
     * Decode JSON-string fields (sizes/samples/selectedOptions/items_json
     * etc.) before validation runs, so downstream code sees arrays.
     */
    protected function prepareForValidation(): void
    {
        foreach ([
            'sizes', 'samples', 'selectedOptions',
            'item_config_json', 'items_json', 'addons_json',
            'breakdown_json', 'print_parts_json', 'incomplete_fields',
        ] as $key) {
            $value = $this->input($key);
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $this->merge([$key => $decoded]);
                }
            }
        }
    }

    public function messages(): array
    {
        return [
            'client.required_without'    => 'Client is required.',
            'client_id.required_without' => 'Client is required.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            $cleanField = preg_replace('/\.\d+$/', '', $field);
            $cleanField = match ($cleanField) {
                'client_id' => 'client',
                default     => $cleanField,
            };
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
