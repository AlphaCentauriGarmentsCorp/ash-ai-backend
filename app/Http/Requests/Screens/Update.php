<?php

namespace App\Http\Requests\Screens;

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
     * SM Rework CP4 — added 'status' so the Screen Inventory edit screen
     * can manually correct a screen's status (mark it washed / damaged /
     * back to available). 'in_use' is deliberately NOT a valid target
     * here — that value is system-derived from an active
     * screen_assignments row (see ScreenAssignmentService, SM Rework
     * CP2) and should never be hand-picked; it would create a screen
     * marked "in use" with nothing actually holding it.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'          => 'sometimes|string|max:255',
            'address'       => 'sometimes|string|max:255',
            'size'          => 'sometimes|string|max:255',
            'mesh_count'    => 'sometimes|string|max:255',
            'status'        => 'sometimes|nullable|in:available,for_reclaim,damaged',
        ];
    }
}
