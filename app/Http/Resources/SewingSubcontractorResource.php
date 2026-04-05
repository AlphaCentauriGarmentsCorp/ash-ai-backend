<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SewingSubcontractorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'address'        => $this->address,
            'rate_per_pcs'   => $this->rate_per_pcs,
            'contact_number' => $this->contact_number,
            'email'          => $this->email,
            'created_at'     => $this->created_at?->toDateTimeString(),
            'updated_at'     => $this->updated_at?->toDateTimeString(),
        ];
    }
}
