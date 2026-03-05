<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EquipmentInventoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'location_id'   => $this->location_id,
            'location'      => $this->whenLoaded('location'),
            'sku'           => $this->sku,
            'name'          => $this->name,
            'quantity'      => $this->quantity,
            'color'         => $this->color,
            'model'         => $this->model,
            'material'      => $this->material,
            'price'         => $this->price,
            'penalty'       => $this->penalty,
            'design'        => $this->design,
            'description'   => $this->description,
            'image'         => $this->image,
            'receipt'       => json_decode($this->receipt, true),
            'qr_code'       => $this->qr_code,
            'status'        => $this->status,
            'in_use'        => $this->in_use,
            'missing'       => $this->missing,
            'created_at'    => $this->created_at?->toDateTimeString(),
            'updated_at'    => $this->updated_at?->toDateTimeString(),
        ];
    }
}
