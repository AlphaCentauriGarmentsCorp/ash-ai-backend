<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDesignResource extends JsonResource
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
            'order_id' => $this->order_id,
            'artist_id' => $this->artist_id,
            'notes' => $this->notes,
            'size_label' => $this->size_label,
            'placements' => OrderDesignPlacementResource::collection(
                $this->whenLoaded('placements')
            ),
            'created_at' => $this->created_at,
        ];
    }
}
