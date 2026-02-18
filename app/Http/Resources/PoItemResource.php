<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PoItemResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'sku' => $this->sku,
            'design_code' => $this->design_code,
            'color' => $this->color,
            'size' => $this->size,
            'quantity' => $this->quantity,
            'qr_path' => $this->qr_path,
            'barcode_path' => $this->barcode_path,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
