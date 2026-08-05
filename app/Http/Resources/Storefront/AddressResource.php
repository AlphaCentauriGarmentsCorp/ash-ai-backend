<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'name' => $this->name,
            'phone' => $this->phone,
            'street' => $this->street,
            'barangay' => $this->barangay,
            'city' => $this->city,
            'province' => $this->province,
            'region' => $this->region,
            'postal' => $this->postal,
            'is_default_shipping' => $this->is_default_shipping,
            'is_default_billing' => $this->is_default_billing,
        ];
    }
}
