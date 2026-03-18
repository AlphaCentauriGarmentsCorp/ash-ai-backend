<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScreenCheckingResource extends JsonResource
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
            'status' => $this->status,
            'verification_date' => $this->verification_date,
            'verified_by' => $this->verified_by,

            'items' => ScreenCheckingItemResource::collection(
                $this->whenLoaded('items')
            ),

            'created_at' => $this->created_at,
        ];
    }
}
