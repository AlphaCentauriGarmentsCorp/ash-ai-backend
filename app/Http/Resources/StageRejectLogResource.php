<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StageRejectLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'order_id'       => $this->order_id,
            'order_stage_id' => $this->order_stage_id,
            'quantity_pcs'   => $this->quantity_pcs,

            'photo_path'     => $this->photo_path,
            'photo_url'      => $this->photo_path
                ? Storage::disk('public')->url($this->photo_path)
                : null,

            'notes'          => $this->notes,

            'logged_by'      => $this->whenLoaded('loggedBy', fn () => [
                'id'   => $this->loggedBy?->id,
                'name' => $this->loggedBy?->name,
            ]),

            'stage'          => $this->whenLoaded('stage', fn () => $this->stage ? [
                'id'       => $this->stage->id,
                'stage'    => $this->stage->stage,
                'sequence' => $this->stage->sequence,
            ] : null),

            'created_at'     => $this->created_at?->toDateTimeString(),
        ];
    }
}
