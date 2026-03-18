<?php

namespace App\Http\Requests\ScreenMaking;

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
    public function rules()
    {
        return [
            'assignments' => 'required|array',
            'assignments.*.order_id' => 'required|exists:orders,id',
            'assignments.*.placement_id' => 'required|exists:order_design_placements,id',
            'assignments.*.screen_id' => 'required|exists:screens,id',
            'assignments.*.color_index' => 'required|integer|min:1'
        ];
    }
}
