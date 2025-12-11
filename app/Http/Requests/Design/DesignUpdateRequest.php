<?php

namespace App\Http\Requests\Design;

use Illuminate\Foundation\Http\FormRequest;

class DesignUpdateRequest extends FormRequest
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
            'artist_id' => 'sometimes|string',
            'po_number' => 'sometimes|string',
            'design_name' => 'sometimes|string',
            'type_printing_method' => 'sometimes|string',
            'resolution' => 'sometimes|string',
            'color_count' => 'sometimes|string',
            'mockup_files' => 'sometimes|string',
            'production_diles' => 'sometimes|string',
            'design_placements' => 'sometimes|string',
            'color_palette' => 'sometimes|string',
            'notes' => 'sometimes|string',
            'status' => 'sometimes|string',
            'version' => 'sometimes|string',
        ];
    }
}
