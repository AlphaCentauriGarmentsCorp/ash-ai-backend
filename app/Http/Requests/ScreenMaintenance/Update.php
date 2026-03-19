<?php

namespace App\Http\Requests\ScreenMaintenance;

use Illuminate\Foundation\Http\FormRequest;

class Update extends FormRequest
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
        $status = $this->input('status');

        $rules = [
            'screen_id' => 'sometimes|exists:screens,id',
            'maintenance_type' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:255',
            'assigned_to' => 'sometimes|nullable|exists:users,id',
            'status' => 'sometimes|string|in:Pending,In Progress,Completed',
        ];

        // Pending status: block timestamps
        if ($status === 'Pending') {
            $rules['start_timestamp'] = 'prohibited';
            $rules['end_timestamp'] = 'prohibited';
        }
        // In Progress: allow start_timestamp, block end_timestamp
        elseif ($status === 'In Progress') {
            $rules['start_timestamp'] = 'nullable|date_format:Y-m-d H:i:s';
            $rules['end_timestamp'] = 'prohibited';
        }
        // Completed: allow manual timestamps for backfilled historical records
        elseif ($status === 'Completed') {
            $rules['start_timestamp'] = 'nullable|date_format:Y-m-d H:i:s|before_or_equal:now';
            $rules['end_timestamp'] = 'nullable|date_format:Y-m-d H:i:s|before_or_equal:now|after_or_equal:start_timestamp';
        }
        // No status change: validate timestamp relationship
        else {
            $rules['start_timestamp'] = 'nullable|date_format:Y-m-d H:i:s';
            $rules['end_timestamp'] = 'nullable|date_format:Y-m-d H:i:s|after_or_equal:start_timestamp';
        }

        return $rules;
    }
}
