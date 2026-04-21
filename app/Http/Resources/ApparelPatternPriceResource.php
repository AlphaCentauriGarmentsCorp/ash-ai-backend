<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApparelPatternPriceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'apparel_type_id' => $this->apparel_type_id,
            'pattern_type_id' => $this->pattern_type_id,
            'apparel_type_name' => $this->apparel_type_name,
            'pattern_type_name' => $this->pattern_type_name,
            'price' => $this->price,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
