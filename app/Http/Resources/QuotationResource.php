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
            'client_id' => $this->client_id,
            'client_name' => $this->client_name,
            'client_email' => $this->client_email,
            'client_facebook' => $this->client_facebook,
            'client_brand' => $this->client_brand,
            'apparel_type_id' => $this->apparel_type_id,
            'pattern_type_id' => $this->pattern_type_id,
            'shirt_color' => $this->shirt_color,
            'apparel_neckline_id' => $this->apparel_neckline_id,
            'print_method_id' => $this->print_method_id,
            'special_print' => $this->special_print,
            'print_area' => $this->print_area,
            'free_items' => $this->free_items,
            'notes' => $this->notes,
            'custom_pattern_image' => $this->custom_pattern_image,

            // ── Issue 7: label spec + shared design (for Edit hydration + PDF)
            'brand_label' => $this->brand_label_json,
            'care_label' => $this->care_label_json,
            'label_design_path' => $this->label_design_path,

            'subtotal' => $this->subtotal,
            'discount_type' => $this->discount_type,
            'discount_price' => $this->discount_price,
            'discount_amount' => $this->discount_amount,
            'grand_total' => $this->grand_total,

            'item_config' => $this->item_config_json,
            'items' => $this->items_json,
            'addons' => $this->addons_json,
            'breakdown' => $this->breakdown_json,
            'sample_breakdown' => $this->breakdown_json['sample_breakdown'] ?? null,
            'print_parts_total' => $this->breakdown_json['print_parts_total'] ?? null,
            'print_parts' => $this->print_parts_json,

            'pdf_path' => $this->pdf_path,
            'status' => $this->status,
            // ── Issue 8: Graphic Artist design review (read-only for CSR;
            // editable only via the GA review surface). null status = the
            // quotation has not been sent to the GA yet.
            'design_review_status' => $this->design_review_status,
            'design_color_count' => $this->design_color_count,
            'design_review_note' => $this->design_review_note,
            'design_reviewed_at' => $this->design_reviewed_at,
            'design_reviewer' => $this->whenLoaded('designReviewer', fn () => $this->designReviewer?->name),
            // Issue 12: the legal next statuses from the current one, sourced
            // from the model's STATUS_TRANSITIONS state machine. The View page
            // renders status-action buttons directly from this, so the UI can
            // never offer a transition the backend would reject (single source
            // of truth — no hardcoded map on the frontend).
            'allowed_transitions' => \App\Models\Quotation::STATUS_TRANSITIONS[$this->resource->normalizedStatus()] ?? [],
            'user' => $this->whenLoaded('user'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}