<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Storefront\Customer
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            // Y-m-d so the client can split it into the form's month/day/year
            // selects without guessing at a locale.
            'birth_date' => optional($this->birth_date)->format('Y-m-d'),
            'gender' => $this->gender,
        ];
    }
}
