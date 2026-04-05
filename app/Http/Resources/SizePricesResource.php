<?php

namespace App\Http\Resources;

use App\Models\TshirtSize;
use App\Models\TshirtTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SizePricesResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'shirt_id'  => $this->shirt_id,
            'size_id'   => $this->size_id,
            'price'     => $this->price,
            'shirt' => new TshirtTypeResource($this->whenLoaded('shirt')),
            'size' => new TshirtSizeResource($this->whenLoaded('size')),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
