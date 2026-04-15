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

        'shirt_color'   => 'nullable|string|max:255',
        'free_items'    => 'nullable|string|max:255',
        'notes'         => 'nullable|string',

        'items_json'        => 'sometimes',
        'addons_json'       => 'nullable',
        'breakdown_json'    => 'nullable',

        'subtotal'        => 'sometimes|numeric|min:0',
        'discount_type'   => 'nullable|string|max:50',
        'discount_price'  => 'nullable|numeric|min:0',
        'grand_total'     => 'sometimes|numeric|min:0',

        // new field
        'print_parts_json' => 'nullable|array',
        'print_parts_json.*.part' => 'required_with:print_parts_json|in:Front,Back,Sleeves',
        'print_parts_json.*.color_count' => 'required_with:print_parts_json|integer|min:1|max:20',
        'print_parts_json.*.image' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:4096',
        ];
    }
}
