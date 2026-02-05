<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'name' => $this->name,
            'email' => $this->email,
            'contact_number' => $this->contact_number,
            'address' => $this->address,
            'notes' => $this->notes,

            'brands' => $this->whenLoaded('brands', function () {
                return $this->brands->map(function ($brand) {
                    return [
                        'id' => $brand->id,
                        'name' => $brand->brand_name,
                        'logo' => asset($brand->logo_url),
                    ];
                });
            }),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
