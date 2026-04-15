<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
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
            'quotation_id' => $this->quotation_id,
            'user_id' => $this->user_id,
            'client_name' => $this->client_name,
            'client_email' => $this->client_email,
            'client_brand' => $this->client_brand,
            'shirt_color' => $this->shirt_color,
            'free_items' => $this->free_items,
            'notes' => $this->notes,

            'subtotal' => $this->subtotal,
            'discount_type' => $this->discount_type,
            'discount_price' => $this->discount_price,
            'grand_total' => $this->grand_total,

            'items' => $this->items_json,
            'addons' => $this->addons_json,
            'breakdown' => $this->breakdown_json,
            'print_parts' => $this->print_parts_json,

            'status' => $this->status,
            'user' => $this->whenLoaded('user'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
