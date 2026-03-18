<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScreenMakingResource extends JsonResource
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
            'placement_id' => $this->placement_id,
            'screen_id' => $this->screen_id,
            'color_index' => $this->color_index,
            'created_at' => $this->created_at
        ];
    }
}
