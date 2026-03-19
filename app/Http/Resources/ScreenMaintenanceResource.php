<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScreenMaintenanceResource extends JsonResource
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
            'screen_id' => $this->screen_id,
            'screen' => $this->whenLoaded('screen'),

            'maintenance_type' => $this->maintenance_type,
            'description' => $this->description,
            'assigned_to' => $this->assigned_to,
            'user' => $this->whenLoaded('employee'),

            'start_timestamp' => $this->start_timestamp ? $this->start_timestamp->toDateTimeString() : null,
            'end_timestamp' => $this->end_timestamp ? $this->end_timestamp->toDateTimeString() : null,
            'status' => $this->status,

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
