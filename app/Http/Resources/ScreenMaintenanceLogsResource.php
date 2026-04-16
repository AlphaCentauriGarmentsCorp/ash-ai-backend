<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScreenMaintenanceLogsResource extends JsonResource
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
            'screen_maintained' => [
                'id' => $this->screen_id,
                'details' => $this->whenLoaded('screen'),
            ],
            'responsible_employee' => [
                'id' => $this->assigned_to,
                'details' => $this->whenLoaded('employee'),
            ],
            'maintenance_type' => $this->maintenance_type,
            'timestamps' => [
                'started_at' => $this->start_timestamp ? $this->start_timestamp->toDateTimeString() : null,
                'ended_at' => $this->end_timestamp ? $this->end_timestamp->toDateTimeString() : null,
                'created_at' => $this->created_at?->toDateTimeString(),
                'updated_at' => $this->updated_at?->toDateTimeString(),
            ],
            'notes' => $this->notes,
            'materials_used' => $this->materials_used,
        ];
    }
}
