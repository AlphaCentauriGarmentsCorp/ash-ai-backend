<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing quotation resource.
 *
 * Sensitive fields stripped:
 *   - client_name, client_email, client_brand   (identity)
 *   - subtotal, discount_type, discount_price,
 *     grand_total                                (pricing)
 *   - items_json[*].price                        (per-item pricing)
 *
 * Safe fields exposed:
 *   - id, quotation_id                           (identification)
 *   - shirt_color, free_items, notes             (order details)
 *   - print_parts_json → full (part, color_count, price_per_color, image)
 *   - status, created_at, updated_at
 */
class PublicQuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // Identifiers only — no names/emails
            'id'           => $this->id,
            'quotation_id' => $this->quotation_id,

            // Order details
            'shirt_color'  => $this->shirt_color,
            'free_items'   => $this->free_items,
            'notes'        => $this->notes,
            'status'       => $this->status,

            // Print parts — full data (pricing now included per color)
            'print_parts'  => $this->print_parts_json,

            'created_at'   => $this->created_at?->toDateTimeString(),
            'updated_at'   => $this->updated_at?->toDateTimeString(),
        ];
    }
}
