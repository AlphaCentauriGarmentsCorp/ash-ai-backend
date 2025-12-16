<?php

namespace App\Http\Requests\OrderProcesses;

use Illuminate\Foundation\Http\FormRequest;

class OrderProcessesStoreRequest extends FormRequest
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
            'started_at' => 'required|string|max:50',
            'completed_at' => 'required|string|max:50',
            'deadline' => 'required|string|max:50',
            'status' => 'required|string|max:50',
            'notes' => 'required|string|max:50',
        ];
    }
}
