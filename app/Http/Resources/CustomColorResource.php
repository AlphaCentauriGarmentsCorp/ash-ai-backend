<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GA Portal CP1 — a custom color, tagged source='custom' so the picker
 * can group it apart from the official Pantone catalog.
 */
class CustomColorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'source'       => 'custom',
            'id'           => $this->id,
            'name'         => $this->name,
            'hexcolor'     => $this->hexcolor,
            'pantone_code' => $this->pantone_code,
            'pick_count'   => (int) $this->pick_count,
            'created_at'   => $this->created_at?->toDateTimeString(),
            'updated_at'   => $this->updated_at?->toDateTimeString(),
        ];
    }
}
