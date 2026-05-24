<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PricingSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'unit' => $this->unit,
            'group' => $this->group,
            'description' => $this->description,
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
