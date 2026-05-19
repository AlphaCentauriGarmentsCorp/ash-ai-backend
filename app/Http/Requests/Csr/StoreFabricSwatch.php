<?php

namespace App\Http\Requests\Csr;

use Illuminate\Foundation\Http\FormRequest;

class StoreFabricSwatch extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:128'],
            'pantone_id'   => ['nullable', 'integer', 'exists:pantones,id'],
            'hex_color'    => ['nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/', 'max:8'],
            'fabric_type'  => ['nullable', 'string', 'max:64'],
            'gsm'          => ['nullable', 'integer', 'min:0', 'max:1000'],
            'collection'   => ['nullable', 'string', 'max:64'],
            'supplier_id'  => ['nullable', 'integer', 'exists:suppliers,id'],
            'material_id'  => ['nullable', 'integer', 'exists:materials,id'],
            'color_family' => ['nullable', 'string', 'max:32'],
            'notes'        => ['nullable', 'string'],
            'photo'        => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
