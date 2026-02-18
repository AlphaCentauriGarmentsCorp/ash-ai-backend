<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_code' => $this->po_code,
            'client_id' => $this->client_id,
            'client_brand' => $this->client_brand,
            'deadline' => $this->deadline?->toDateString(),
            'priority' => $this->priority,
            'brand' => $this->brand,

            'courier' => $this->courier,
            'method' => $this->method,
            'receiver_name' => $this->receiver_name,
            'receiver_contact' => $this->receiver_contact,
            'address' => $this->address,

            'design_name' => $this->design_name,
            'apparel_type' => $this->apparel_type,
            'pattern_type' => $this->pattern_type,
            'service_type' => $this->service_type,
            'print_method' => $this->print_method,
            'print_service' => $this->print_service,
            'size_label' => $this->size_label,
            'print_label_placement' => $this->print_label_placement,

            'fabric_type' => $this->fabric_type,
            'fabric_supplier' => $this->fabric_supplier,
            'fabric_color' => $this->fabric_color,
            'thread_color' => $this->thread_color,
            'ribbing_color' => $this->ribbing_color,

            'placement_measurements' => $this->placement_measurements,
            'notes' => $this->notes,
            'options' => $this->options,

            'freebie_items' => $this->freebie_items,
            'freebie_color' => $this->freebie_color,
            'freebie_others' => $this->freebie_others,

            'payment_method' => $this->payment_method,
            'payment_plan' => $this->payment_plan,
            'total_price' => $this->total_price,
            'average_unit_price' => $this->average_unit_price,
            'total_quantity' => $this->total_quantity,
            'deposit' => $this->deposit,

            'design_files' => $this->design_files,
            'design_mockup' => $this->design_mockup,
            'size_label_files' => $this->size_label_files,
            'freebies_files' => $this->freebies_files,

            'qr_path' => $this->qr_path,
            'barcode_path' => $this->barcode_path,

            'items' => PoItemResource::collection($this->whenLoaded('items')),
            'client' => $this->whenLoaded('client'),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
