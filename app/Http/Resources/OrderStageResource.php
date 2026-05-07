<?php

namespace App\Http\Resources;

use App\Support\WorkflowStages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderStageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $def = WorkflowStages::find($this->stage);

        return [
            'id'            => $this->id,
            'order_id'      => $this->order_id,
            'stage'         => $this->stage,
            'label'         => $def['label'] ?? $this->stage,
            'group'         => $def['group'] ?? null,
            'role'          => $def['role'] ?? null,
            'sequence'      => $this->sequence,
            'status'        => $this->status,
            'started_at'    => $this->started_at?->toDateTimeString(),
            'completed_at'  => $this->completed_at?->toDateTimeString(),
            'delayed_at'    => $this->delayed_at?->toDateTimeString(),
            'assigned_to'   => $this->assigned_to,
            'assigned_role' => $this->assigned_role,
            'notes'         => $this->notes,
            'duration_minutes' => $this->durationMinutes(),
            'created_at'    => $this->created_at?->toDateTimeString(),
            'updated_at'    => $this->updated_at?->toDateTimeString(),
        ];
    }
}
