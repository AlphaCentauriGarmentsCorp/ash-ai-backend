<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class Store extends FormRequest
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

            'client_name'   => 'required|string|max:255',
            'client_email'  => 'nullable|email|max:255',
            'client_brand'  => 'nullable|string|max:255',

            // Shirt / Notes
            'shirt_color'   => 'nullable|string|max:255',
            'free_items'    => 'nullable|string|max:255',
            'notes'         => 'nullable|string',

            // Items (required JSON)
            'items_json'                 => 'required|array|min:1',
            'items_json.*.quantity'      => 'required|numeric|min:1',
            // optional: add more strict rules if you have these fields
            'items_json.*.name'          => 'nullable|string|max:255',
            'items_json.*.price'         => 'nullable|numeric|min:0',

            // Addons (optional JSON)
            'addons_json'                => 'nullable|array',
            'addons_json.*.name'         => 'required|string|max:255',
            'addons_json.*.price'        => 'required|numeric|min:0',

            // Breakdown (optional JSON)
            'breakdown_json'             => 'nullable|array',


            'subtotal'        => 'required|numeric|min:0',
            'discount_type'   => 'nullable|string|max:50',
            'discount_price'  => 'nullable|numeric|min:0',
            'grand_total'     => 'required|numeric|min:0',
        ];
    }
}
