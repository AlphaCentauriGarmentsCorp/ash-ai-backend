<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PoItemsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'po_id'           => $this->po_id,
            'design_code'     => $this->design_code,
            'color'           => $this->color,
            'size'            => $this->size,
            'quantity_ordered'=> $this->quantity_ordered,
            'variant_code'    => $this->variant_code,
            'variant_barcode' => $this->variant_barcode,
            'variant_qr_code' => $this->variant_qr_code,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];

    }
}
