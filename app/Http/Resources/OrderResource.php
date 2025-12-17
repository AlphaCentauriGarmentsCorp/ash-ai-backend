<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
            'po_number' => $this->po_number,
            'client_id' => $this->client_id,
            'brand_id' => $this->brand_id,
            'channel' => $this->channel,
            'order_type' => $this->order_type,
            'design_name' => $this->design_name,
            'type_fabric' => $this->type_fabric,
            'type_size' => $this->type_size,
            'type_garment' => $this->type_garment,
            'type_printing_method' => $this->type_printing_method,
            'design_files' => $this->design_files,
            'artist_filename' => $this->artist_filename,
            'mockup_url' => $this->mockup_url,
            'mockup_images' => $this->mockup_images,
            'mockup_notes' => $this->mockup_notes,
            'print_location' => $this->print_location,
            'total_quantity' => $this->total_quantity,
            'size_breakdown' => $this->size_breakdown,
            'target_date' => $this->target_date,
            'instruction_files' => $this->instruction_files,
            'instruction_notes' => $this->instruction_notes,
            'unit_price' => $this->unit_price,
            'desposit_percentage' => $this->desposit_percentage,
            'payment_terms' => $this->payment_terms,
            'currency' => $this->currency,
            'status' => $this->status,
            'created_at'  => $this->created_at?->toDateTimeString(),
            'updated_at'  => $this->updated_at?->toDateTimeString(),
        ];
    }
}
