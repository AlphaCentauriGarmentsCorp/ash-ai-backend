<?php

namespace App\Http\Requests\GraphicArtist;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * GA Portal CP1 — Validates a print-placement upsert from the Graphic
 * Artist portal (old-portal "Print Location" editor brought into ASH).
 *
 * multipart/form-data (sent as POST + _method=PUT, same convention as
 * label-assets). `pantones` may arrive either as a real array or as a
 * JSON-encoded string (FormData cannot carry nested arrays natively) —
 * prepareForValidation() decodes the string form.
 *
 * Pantone entries are free-form at this layer; the service normalises
 * each entry to either a pantones-table ID reference or an inline
 * descriptor ({pantone_code, name, hexcolor}). color_count is the slot
 * count (defaulted from the quotation part, artist-adjustable) and is
 * intentionally NOT forced to match count(pantones) — unfilled slots
 * are allowed and surface as soft completion warnings.
 */
class UpsertPlacement extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('pantones');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $this->merge([
                'pantones' => is_array($decoded) ? $decoded : [],
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'order_id'       => 'required|integer|exists:orders,id',
            'order_stage_id' => 'required|integer|exists:order_stages,id',
            // Present on update, absent on create.
            'id'             => 'nullable|integer|exists:order_design_placements,id',
            'type'           => 'required|string|max:64',
            'color_count'    => 'nullable|integer|min:0|max:20',
            'pantones'       => 'nullable|array|max:20',
            // Per-location artwork / mockup image (the old portal's
            // per-location UPLOAD IMAGE box). Optional on every save.
            'artwork'        => 'nullable|file|mimes:png,jpg,jpeg,webp,pdf|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Placement type is required.',
            'artwork.mimes' => 'Artwork type not allowed. Accepted: PNG, JPG, WebP, PDF.',
            'artwork.max'   => 'Artwork must be smaller than 10 MB.',
            'pantones.max'  => 'Too many Pantone entries (max 20).',
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
