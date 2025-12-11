<?php

namespace App\Http\Requests\Design;

use Illuminate\Foundation\Http\FormRequest;

class DesignStoreRequest extends FormRequest
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
            'artist_id' => 'required|string',
            'po_number' => 'required|string',
            'design_name' => 'required|string',
            'type_printing_method' => 'required|string',
            'resolution' => 'required|string',
            'color_count' => 'required|string',
            'mockup_files' => 'required|string',
            'production_diles' => 'required|string',
            'design_placements' => 'required|string',
            'color_palette' => 'required|string',
            'notes' => 'required|string',
            'status' => 'required|string',
            'version' => 'required|string',
        ];
    }
}
