<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * OrderResource — JSON shape returned to the frontend for an Order.
 *
 * Maps the new quotation-derived `orders` schema to the keys the
 * frontend reads. Legacy keys that no longer have a matching DB column
 * (deadline, courier, fabric_*, total_quantity, etc.) are still
 * exposed but as `null` so older frontend pages that read them don't
 * crash — they'll just render empty until rewritten in Phase 5.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identity + linkage
            'id'           => $this->id,
            'po_code'      => $this->po_code,
            'quotation_id' => $this->quotation_id,

            // Client
            'client_id'    => $this->client_id,
            'client_name'  => $this->client_name,
            'client_brand' => $this->client_brand,

            // Apparel / pattern / print method (FK ids + lazy-loaded names)
            'apparel_type_id'     => $this->apparel_type_id,
            'apparel_type_name'   => $this->whenLoaded('apparelType',   fn () => $this->apparelType?->name),
            'pattern_type_id'     => $this->pattern_type_id,
            'pattern_type_name'   => $this->whenLoaded('patternType',   fn () => $this->patternType?->name),
            'apparel_neckline_id' => $this->apparel_neckline_id,
            'print_method_id'     => $this->print_method_id,
            'print_method_name'   => $this->whenLoaded('printMethod',   fn () => $this->printMethod?->name),

            // Print details
            'shirt_color'   => $this->shirt_color,
            'special_print' => $this->special_print,
            'print_area'    => $this->print_area,

            // Misc descriptive
            'free_items' => $this->free_items,
            'notes'      => $this->notes,

            // Financials
            'discount_type'   => $this->discount_type,
            'discount_price'  => $this->discount_price,
            'discount_amount' => $this->discount_amount,
            'subtotal'        => $this->subtotal,
            'grand_total'     => $this->grand_total,

            // JSON carry-over from the quotation (already array-cast)
            'item_config_json' => $this->item_config_json,
            'items_json'       => $this->items_json,
            'addons_json'      => $this->addons_json,
            'breakdown_json'   => $this->breakdown_json,
            'print_parts_json' => $this->print_parts_json,

            // Artifacts
            'qr_path'      => $this->qr_path,
            'barcode_path' => $this->barcode_path,

            // Status + Phase 1 workflow
            'status'           => $this->status,
            'workflow_status'  => $this->workflow_status,
            'current_stage_id' => $this->current_stage_id,
            'delayed_at'       => $this->delayed_at?->toDateTimeString(),

            // Relations (only included if explicitly loaded)
            'items'             => PoItemResource::collection($this->whenLoaded('items')),
            'samples'           => OrderSamples::collection($this->whenLoaded('samples')),
            'client'            => $this->whenLoaded('client'),
            'orderStages'       => OrderStageResource::collection($this->whenLoaded('orderStages')),
            'orderDesign'       => $this->whenLoaded('orderDesign'),
            'screenAssignment'  => $this->whenLoaded('screenAssignment'),
            'screenChecking'    => $this->whenLoaded('screenChecking'),

            // Timestamps
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),

            // ── Legacy compatibility shims ─────────────────────────────────
            // Older frontend pages (Phase 5 candidates) read these keys.
            // We expose them as null since the columns no longer exist.
            // Removing these keys would crash the pages outright; nulling
            // them out lets the pages render empty until they're rebuilt.
            'brand'                  => null,
            'priority'               => null,
            'deadline'               => null,
            'courier'                => null,
            'method'                 => null,
            'receiver_name'          => null,
            'receiver_contact'       => null,
            'address'                => null,
            'design_name'            => null,
            'apparel_type'           => $this->whenLoaded('apparelType', fn () => $this->apparelType?->name),
            'pattern_type'           => $this->whenLoaded('patternType', fn () => $this->patternType?->name),
            'service_type'           => null,
            'print_method'           => $this->whenLoaded('printMethod', fn () => $this->printMethod?->name),
            'print_service'          => null,
            'size_label'             => null,
            'print_label_placement'  => null,
            'fabric_type'            => null,
            'fabric_supplier'        => null,
            'fabric_color'           => null,
            'thread_color'           => null,
            'ribbing_color'          => null,
            'placement_measurements' => null,
            'options'                => null,
            'freebie_items'          => null,
            'freebie_color'          => null,
            'freebie_others'         => null,
            'payment_method'         => null,
            'payment_plan'           => null,
            'total_price'            => $this->grand_total,    // best alias
            'average_unit_price'     => null,
            'total_quantity'         => null,
            'deposit'                => null,
            'design_files'           => null,
            'design_mockup'          => null,
            'size_label_files'       => null,
            'freebies_files'         => null,
        ];
    }
}
