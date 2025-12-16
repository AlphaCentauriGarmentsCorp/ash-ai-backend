<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderProcessesResource extends JsonResource
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
            'po_id' => $this->po_id,
            'stage' => $this->stage,
            'assigned_by' => $this->assigned_by,
            'assigned_to' => $this->assigned_to,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'deadline' => $this->deadline,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at'  => $this->created_at?->toDateTimeString(),            
            'updated_at'  => $this->updated_at?->toDateTimeString(),
        ];
    }
}
