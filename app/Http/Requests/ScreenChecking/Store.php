<?php

namespace App\Http\Requests\ScreenChecking;

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
            'status' => 'nullable|in:pending,in_progress,completed',
            'verification_date' => 'nullable|date',
            'screens' => 'required|array|min:1',
            'screens.*.placement_id' => 'required',
            'screens.*.screen_id' => 'required|exists:screens,id',
            'screens.*.color_index' => 'required|integer',
            'screens.*.pantone' => 'nullable|string',
            'screens.*.checks' => 'required|array',
            'screens.*.checks.clean' => 'required|',
            'screens.*.checks.no_damage' => 'required|',
            'screens.*.checks.emulsion_ok' => 'required|',
            'screens.*.checks.verified' => 'required|',
            'screens.*.issues' => 'nullable|string',
            'screens.*.verified_at' => 'nullable|date',
        ];
    }
}
