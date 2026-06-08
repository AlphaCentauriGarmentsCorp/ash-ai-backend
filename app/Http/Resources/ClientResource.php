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
            // Derived single-line address (legacy / convenience).
            'address' => $this->address,
            // Change 6 (option B): granular address parts.
            'street_address' => $this->street_address,
            'barangay' => $this->barangay,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,
            'method' => $this->method,
            'courier' => $this->courier,
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
            'orders' => OrderResource::collection(
                $this->whenLoaded('orders')
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
