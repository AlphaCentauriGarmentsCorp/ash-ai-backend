<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $addressParts = array_map('trim', explode('|', $this->address));

        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'contact_person'    => $this->contact_person,
            'contact_number'    => $this->contact_number,
            'email'             => $this->email,
            'street_address'    => $addressParts[0] ?? '',
            'barangay'          => $addressParts[1] ?? '',
            'city'              => $addressParts[2] ?? '',
            'province'          => $addressParts[3] ?? '',
            'postal_code'       => $addressParts[4] ?? '',
            'notes'             => $this->notes,
            'created_at'        => $this->created_at?->toDateTimeString(),
            'updated_at'        => $this->updated_at?->toDateTimeString(),
        ];
    }
}
