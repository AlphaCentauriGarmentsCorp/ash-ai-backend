<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class Update extends FormRequest
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

            'client_name'   => 'sometimes|required|string|max:255',
            'client_email'  => 'nullable|email|max:255',
            'client_brand'  => 'nullable|string|max:255',

            // Shirt / Notes
            'shirt_color'   => 'nullable|string|max:255',
            'free_items'    => 'nullable|string|max:255',
            'notes'         => 'nullable|string',

            // Items (optional on update, but validated if present)
            'items'                 => 'sometimes|array|min:1',
            'items.*.quantity'      => 'required_with:items|numeric|min:1',
            'items.*.name'          => 'nullable|string|max:255',
            'items.*.price'         => 'nullable|numeric|min:0',

            // Addons (optional)
            'addons'                => 'sometimes|array',
            'addons.*.name'         => 'required_with:addons|string|max:255',
            'addons.*.price'        => 'required_with:addons|numeric|min:0',

            // Breakdown (optional)
            'breakdown'             => 'sometimes|array',

            // Totals (only validate if present)
            'subtotal'        => 'sometimes|numeric|min:0',
            'discount_type'   => 'nullable|string|max:50',
            'discount_price'  => 'nullable|numeric|min:0',
            'grand_total'     => 'sometimes|numeric|min:0',
        ];
    }
}
