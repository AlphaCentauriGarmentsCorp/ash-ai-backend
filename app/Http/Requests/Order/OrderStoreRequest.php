<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class OrderStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'po_number' => 'required|string',
            'client_id' => 'required|string',
            'brand_id' => 'required|string',
            'channel' => 'required|string',
            'order_type' => 'required|string',
            'design_name' => 'required|string',
            'type_fabric' => 'required|string',
            'type_size' => 'required|string',
            'type_garment' => 'required|string',
            'type_printing_method' => 'required|string',
            'design_files' => 'required|string',
            'artist_filename' => 'required|string',
            'mockup_url' => 'required|string',
            'mockup_images' => 'required|string',
            'mockup_notes' => 'required|string',
            'print_location' => 'required|string',
            'total_quantity' => 'required|string',
            'size_breakdown' => 'required|string',
            'target_date' => 'required|string',
            'instruction_files' => 'required|string',
            'insturction_notes' => 'required|string',
            'unit_price' => 'required|string',
            'deposit_percentage' => 'required|string',
            'payment_terms' => 'required|string',
            'currency' => 'required|string',
            'status' => 'required|string'
        ];
    }
}
