<?php

namespace App\Http\Requests\OrderProcesses;

use Illuminate\Foundation\Http\FormRequest;

class OrderProcessesUpdateRequest extends FormRequest
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
            'po_id' => 'required|string|max:50',
            'stage' => 'required|string|max:50',
            'assigned_by' => 'required|string|max:50',
            'assigned_to' => 'required|string|max:50',
            'started_at' => 'string|max:50',
            'completed_at' => 'string|max:50',
            'deadline' => 'string|max:50',
            'status' => 'string|max:50',
            'notes' => 'string|max:50',
        ];
    }
}
