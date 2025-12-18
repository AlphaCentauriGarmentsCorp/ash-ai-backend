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
            'artist_id'            => ['required', 'integer', 'exists:users,id'],
            'po_number'            => ['required', 'integer'],
            'design_name'          => ['required', 'string', 'max:255'],
            'type_printing_method' => ['required', 'integer', 'exists:type_printing_methods,id'],
            'resolution'           => ['required', 'string', 'max:255'],
            'color_count'          => ['nullable', 'string', 'max:50'],
            'mockup_files'         => ['nullable', 'string'],
            'production_files'     => ['nullable', 'string'],
            'design_placements'    => ['nullable', 'string'],
            'color_palette'        => ['nullable', 'string'],
            'notes'                => ['nullable', 'string', 'max:255'],
            'status'               => ['nullable', 'string', 'max:50'],
            'version'              => ['nullable', 'integer'],
        ];
    }
}
