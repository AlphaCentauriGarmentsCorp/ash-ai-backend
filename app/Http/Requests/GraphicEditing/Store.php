<?php

namespace App\Http\Requests\GraphicEditing;

use Illuminate\Foundation\Http\FormRequest;

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
            'order_id' => 'required|exists:orders,id',
            'notes' => 'nullable|string',
            'size_label' => 'nullable',
            'placements' => 'required|array|min:1',
            'placements.*.type' => 'required|string',
            'placements.*.mockupImage' => 'nullable|string',
            'placements.*.pantones' => 'nullable|array',
        ];
    }
}
