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
            'po_number' => 'sometimes|string',
            'client_id' => 'sometimes|string',
            'brand_id' => 'sometimes|string',
            'channel' => 'sometimes|string',
            'order_type' => 'sometimes|string',
            'design_name' => 'sometimes|string',
            'type_fabric' => 'sometimes|string',
            'type_size' => 'sometimes|string',
            'type_garment' => 'sometimes|string',
            'type_printing_method' => 'sometimes|string',
            'design_files' => 'sometimes|string',
            'artist_filename' => 'sometimes|string',
            'mockup_url' => 'sometimes|string',
            'mockup_images' => 'sometimes|string',
            'mockup_notes' => 'sometimes|string',
            'print_location' => 'sometimes|string',
            'total_quantity' => 'sometimes|string',
            'size_breakdown' => 'sometimes|string',
            'target_date' => 'sometimes|string',
            'instruction_files' => 'sometimes|string',
            'insturction_notes' => 'sometimes|string',
            'unit_price' => 'sometimes|string',
            'deposit_percentage' => 'sometimes|string',
            'payment_terms' => 'sometimes|string',
            'currency' => 'sometimes|string',
            'status' => 'sometimes|string'
        ];
    }
}
