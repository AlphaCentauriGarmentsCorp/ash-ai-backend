<?php

namespace App\Http\Requests\ScreenMaintenanceLogs;

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
            'screen_id' => 'required|exists:screens,id',
            'maintenance_type' => 'required|string|max:100',
            'notes' => 'nullable|string|max:255',
            'materials_used' => 'nullable|string|max:255',
            'assigned_to' => 'nullable|exists:users,id',
            'start_timestamp' => 'nullable|date_format:Y-m-d H:i:s',
            'end_timestamp' => 'nullable|date_format:Y-m-d H:i:s|after_or_equal:start_timestamp',
        ];
    }
}
