<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'po_code'             => $this->po_code,

            // Traceability
            'quotation_id'        => $this->quotation_id,
            'quotation'           => $this->whenLoaded('quotation'),

            // Client
            'client_id'           => $this->client_id,
            'client_name'         => $this->client_name,
            'client_brand'        => $this->client_brand,
            'client'              => $this->whenLoaded('client'),

            // Apparel config IDs
            'apparel_type_id'     => $this->apparel_type_id,
            'pattern_type_id'     => $this->pattern_type_id,
            'apparel_neckline_id' => $this->apparel_neckline_id,
            'print_method_id'     => $this->print_method_id,

            // Shirt / Print details
            'shirt_color'         => $this->shirt_color,
            'special_print'       => $this->special_print,
            'print_area'          => $this->print_area,
            'free_items'          => $this->free_items,
            'notes'               => $this->notes,

            // Pricing
            'discount_type'       => $this->discount_type,
            'discount_price'      => $this->discount_price,
            'discount_amount'     => $this->discount_amount,
            'subtotal'            => $this->subtotal,
            'grand_total'         => $this->grand_total,

            // JSON blobs
            'item_config'         => $this->item_config_json,
            'items'               => $this->items_json,
            'addons'              => $this->addons_json,
            'breakdown'           => $this->breakdown_json,
            'print_parts'         => $this->print_parts_json,

            // QR / Barcode
            'qr_path'             => $this->qr_path,
            'barcode_path'        => $this->barcode_path,

            'status'              => $this->status,

            // Relations (loaded on demand)
            'items_list'          => PoItemResource::collection($this->whenLoaded('items')),
            'samples'             => OrderSamples::collection($this->whenLoaded('samples')),
            'orderStages'         => $this->whenLoaded('orderStages'),
            'orderDesign'         => $this->whenLoaded('orderDesign'),
            'screenAssignment'    => $this->whenLoaded('screenAssignment'),
            'screenChecking'      => $this->whenLoaded('screenChecking'),
            'tickets'             => $this->whenLoaded('tickets'),

            'created_at'          => $this->created_at?->toDateTimeString(),
            'updated_at'          => $this->updated_at?->toDateTimeString(),
        ];
    }
}
