<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Resolve names from relations (eager-loaded) or fall back to raw IDs
        $apparelTypeName   = $this->whenLoaded('apparelType',  fn() => $this->apparelType?->name);
        $patternTypeName   = $this->whenLoaded('patternType',  fn() => $this->patternType?->name);
        $printMethodName   = $this->whenLoaded('printMethod',  fn() => $this->printMethod?->name);
        $necklineName      = $this->whenLoaded('apparelNeckline', fn() => $this->apparelNeckline?->name);

        // Resolved client name (relation takes priority over stored string)
        $clientName = $this->whenLoaded('client',
            fn() => $this->client?->name ?? $this->client_name,
            $this->client_name
        );

        return [
            'id'                  => $this->id,
            'po_code'             => $this->po_code,
            'status'              => $this->status,

            // Traceability
            'quotation_id'        => $this->quotation_id,
            'quotation'           => $this->whenLoaded('quotation'),

            // Client
            'client_id'           => $this->client_id,
            'client_name'         => $clientName,
            'client_brand'        => $this->client_brand,
            'client'              => $this->whenLoaded('client'),

            // Apparel config — IDs + resolved names
            'apparel_type_id'     => $this->apparel_type_id,
            'pattern_type_id'     => $this->pattern_type_id,
            'apparel_neckline_id' => $this->apparel_neckline_id,
            'print_method_id'     => $this->print_method_id,
            'apparel_type'        => $apparelTypeName,
            'pattern_type'        => $patternTypeName,
            'print_method'        => $printMethodName,
            'apparel_neckline'    => $necklineName,

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

            // JSON blobs — cast to array by model, always arrays here
            'items_json'          => $this->items_json   ?? [],
            'addons_json'         => $this->addons_json  ?? [],
            'breakdown_json'      => $this->breakdown_json ?? [],
            'print_parts_json'    => $this->print_parts_json ?? [],
            'item_config_json'    => $this->item_config_json ?? [],

            // QR / Barcode
            'qr_path'             => $this->qr_path,
            'barcode_path'        => $this->barcode_path,

            // Relations
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