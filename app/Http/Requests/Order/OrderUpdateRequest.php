<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class OrderUpdateRequest extends FormRequest
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
            'po_number' => 'required|string|max:50',
            'client_id' => 'required|string|max:50',
            'brand_id' => 'required|string|max:50',
            'channel' => 'required|string|max:50',
            'order_type' => 'required|string|max:50',
            'design_name' => 'required|string|max:50',
            'type_fabric' => 'required|string|max:50',
            'type_size' => 'required|string|max:50',
            'type_garment' => 'required|string|max:50',
            'type_printing_method' => 'required|string|max:50',
            'design_files' => 'string|max:50',
            'artist_filename' => 'string|max:50',
            'mockup_url' => 'string|max:50',
            'mockup_images' => 'string|max:50',
            'mockup_notes' => 'string|max:50',
            'print_location' => 'string|max:50',
            'total_quantity' => 'string|max:50',
            'size_breakdown' => 'string|max:50',
            'target_date' => 'string|max:50',
            'instruction_files' => 'string|max:50',
            'instruction_notes' => 'string|max:50',
            'unit_price' => 'string|max:50',
            'desposit_percentage' => 'string|max:50',
            'payment_terms' => 'string|max:50',
            'currency' => 'string|max:50',
            'status' => 'string|max:50',
        ];
    }
}
