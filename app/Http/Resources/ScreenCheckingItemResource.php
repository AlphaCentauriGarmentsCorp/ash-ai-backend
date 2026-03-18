<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScreenCheckingItemResource extends JsonResource
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

            'placement_id' => $this->placement_id,
            'screen_id' => $this->screen_id,
            'color_index' => $this->color_index,
            'pantone' => $this->pantone,

            'checks' => [
                'clean' => $this->clean,
                'no_damage' => $this->no_damage,
                'emulsion_ok' => $this->emulsion_ok,
                'verified' => $this->verified,
            ],

            'issues' => $this->issues,
            'verified_at' => $this->verified_at,

            'screen' => $this->whenLoaded('screen'),
            'placement' => $this->whenLoaded('placement'),
        ];
    }
}
