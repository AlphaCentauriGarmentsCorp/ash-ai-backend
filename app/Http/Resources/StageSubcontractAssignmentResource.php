<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageSubcontractAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'order_id'         => $this->order_id,
            'order_stage_id'   => $this->order_stage_id,
            'subcontractor_id' => $this->subcontractor_id,

            'quantity_pcs'     => $this->quantity_pcs,
            'rate_per_pcs'     => $this->rate_per_pcs,
            'total_amount'     => $this->total_amount,

            'status'           => $this->status,
            'sent_at'          => $this->sent_at?->toDateTimeString(),
            'returned_at'      => $this->returned_at?->toDateTimeString(),
            'notes'            => $this->notes,

            'subcontractor'    => $this->whenLoaded('subcontractor', fn () => $this->subcontractor ? [
                'id'           => $this->subcontractor->id,
                'name'         => $this->subcontractor->name,
                'service_type' => $this->subcontractor->service_type ?? null,
            ] : null),

            'stage'            => $this->whenLoaded('stage', fn () => $this->stage ? [
                'id'       => $this->stage->id,
                'stage'    => $this->stage->stage,
                'sequence' => $this->stage->sequence,
            ] : null),

            'created_at'       => $this->created_at?->toDateTimeString(),
            'updated_at'       => $this->updated_at?->toDateTimeString(),
        ];
    }
}
