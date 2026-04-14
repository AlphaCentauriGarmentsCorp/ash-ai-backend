<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingMethodResource extends JsonResource
{
    public function toArray($request): array
{
    return [
        'id' => $this->id,
        'courier_id' => $this->courier_id,

        'courier' => [
            'id' => $this->courier->id ?? null,
            'name' => $this->courier->name ?? null,
            'description' => $this->courier->description ?? null,
        ],

        'name' => $this->name,
        'description' => $this->description,
        'created_at' => $this->created_at?->toDateTimeString(),
        'updated_at' => $this->updated_at?->toDateTimeString(),
    ];
}
}