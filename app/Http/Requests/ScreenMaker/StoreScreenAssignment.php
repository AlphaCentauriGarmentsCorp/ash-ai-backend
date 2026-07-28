<?php

namespace App\Http\Requests\ScreenMaker;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * SM Rework CP2 — validates one "Screens Used" slot save.
 *
 * One placement/colour combination per request (e.g. Front, colour 2),
 * matching how the picker saves a row at a time.
 *
 * Fields:
 *   - order_stage_id  (required FK — the active screen_making stage)
 *   - placement_id    (required FK — which print location)
 *   - color_index     (required int >= 1 — which ink colour on that
 *                      placement; upper bound checked in the service
 *                      against the placement's color_count)
 *   - screen_id       (required FK — the physical screen from inventory)
 */
class StoreScreenAssignment extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_stage_id' => 'required|integer|exists:order_stages,id',
            'placement_id'   => 'required|integer|exists:order_design_placements,id',
            'color_index'    => 'required|integer|min:1',
            'screen_id'      => 'required|integer|exists:screens,id',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors()->toArray(),
            ], 422),
        );
    }
}
