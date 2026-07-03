<?php

namespace App\Http\Resources;

use App\Models\OrderPayment;
use App\Support\WorkflowStages;
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
            'apparel_neckline'    => $this->whenLoaded('apparelNeckline', fn () => $this->apparelNeckline?->name),
            'apparel_neckline_name' => $this->whenLoaded('apparelNeckline', fn () => $this->apparelNeckline?->name),
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
            'status'           => $this->displayStatus(),
            'workflow_status'  => $this->workflow_status,
            'is_incomplete'    => (bool) $this->is_incomplete,
            'incomplete_fields'=> $this->incomplete_fields ?? [],
            // Editable until the order enters production (no verified payment).
            'is_editable'      => $this->editableNow(),
            'current_stage_id' => $this->current_stage_id,
            'current_stage'    => $this->whenLoaded('currentStage', fn () => $this->currentStage ? ucwords(str_replace('_', ' ', $this->currentStage->stage)) : null),
            'assigned_to'      => $this->whenLoaded('assignedCsr', fn () => $this->assignedCsr?->name),
            'progress_pct'     => isset($this->total_stages_count)
                ? ((int) $this->total_stages_count > 0
                    ? (int) round(($this->completed_stages_count / $this->total_stages_count) * 100)
                    : 0)
                : null,
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
            // Null for live orders; set once soft-deleted. Lets the "Show
            // deleted" toggle render when each order was removed.
            'deleted_at' => $this->deleted_at?->toDateTimeString(),

            // ── Legacy compatibility shims ─────────────────────────────────
            // Older frontend pages (Phase 5 candidates) read these keys.
            // We expose them as null since the columns no longer exist.
            // Removing these keys would crash the pages outright; nulling
            // them out lets the pages render empty until they're rebuilt.
            'brand'                  => $this->brand,
            'priority'               => $this->priority,
            'deadline'               => $this->deadline,
            'courier'                => $this->courier,
            'method'                 => $this->method,
            'receiver_name'          => $this->receiver_name,
            'receiver_contact'       => $this->contact_number,
            'contact_number'         => $this->contact_number,
            'street_address'         => $this->street_address,
            'barangay_address'       => $this->barangay_address,
            'city_address'           => $this->city_address,
            'province_address'       => $this->province_address,
            'postal_address'         => $this->postal_address,
            'address'                => collect([
                                            $this->street_address,
                                            $this->barangay_address,
                                            $this->city_address,
                                            $this->province_address,
                                            $this->postal_address,
                                        ])->filter()->implode(', ') ?: null,
            'design_name'            => $this->design_name,
            'apparel_type'           => $this->whenLoaded('apparelType', fn () => $this->apparelType?->name),
            'pattern_type'           => $this->whenLoaded('patternType', fn () => $this->patternType?->name),
            'service_type'           => $this->service_type,
            'print_method'           => $this->whenLoaded('printMethod', fn () => $this->printMethod?->name),
            'print_service'          => $this->print_service,
            // Labels — mirror QuotationResource. brand_label / care_label are
            // arrays (via the model's array cast); label_design_path is the raw
            // stored path or external link.
            'brand_label'            => $this->brand_label_json,
            'care_label'             => $this->care_label_json,
            'label_design_path'      => $this->label_design_path,
            'fabric_type'            => $this->fabric_type,
            'fabric_supplier'        => $this->fabric_supplier,
            'fabric_color'           => $this->fabric_color,
            'thread_color'           => $this->thread_color,
            'ribbing_color'          => $this->ribbing_color,
            'placement_measurements' => null,
            'options'                => null,
            'freebie_items'          => $this->freebie_items,
            'freebie_color'          => $this->freebie_color,
            'freebie_others'         => $this->freebie_others,
            'payment_method'         => $this->payment_method,
            'payment_plan'           => $this->payment_plan,
            'total_price'            => $this->grand_total,    // best alias
            'average_unit_price'     => null,
            'total_quantity'         => null,
            'deposit'                => $this->deposit_percentage,
            'deposit_percentage'     => $this->deposit_percentage,
            'design_files'           => null,
            'design_mockup'          => null,
            'freebies_files'         => null,
        ];
    }

    /**
     * Whether the order may still be edited — true until it enters
     * production, i.e. until a payment has been verified (the chokepoint
     * that advances it past the gate). Uses the withCount alias when the
     * caller provided it (list view) to avoid an N+1, else a relation/query.
     */
    private function editableNow(): bool
    {
        if (isset($this->resource->verified_payments_count)) {
            return (int) $this->resource->verified_payments_count === 0;
        }
        if ($this->resource->relationLoaded('payments')) {
            return ! $this->resource->payments->contains(
                fn ($p) => $p->status === OrderPayment::STATUS_VERIFIED
            );
        }
        return ! $this->resource->payments()
            ->where('status', OrderPayment::STATUS_VERIFIED)
            ->exists();
    }

    /**
     * Coarse display status derived from the live workflow.
     *
     * The stored `status` column is written once at creation and never
     * advanced, so it sticks on "Pending Approval". `workflow_status` is the
     * maintained source of truth (OrderStagesService recompute), so map its
     * current stage to the badge the UI shows. Explicit terminal states
     * (Cancelled / Rejected) that the stage pipeline does not model are
     * preserved as-is.
     */
    private function displayStatus(): string
    {
        $stored = $this->status;
        if (in_array($stored, ['Cancelled', 'Rejected'], true)) {
            return $stored;
        }

        $wf = $this->workflow_status;
        if ($wf === null || $wf === '') {
            return $stored ?: 'Pending Approval';
        }
        if ($wf === 'order_completed') {
            return 'Completed';
        }

        $seq = WorkflowStages::sequenceOf($wf);
        if ($seq === null) {
            return $stored ?: 'Pending Approval';
        }
        if ($seq >= 21) {            // order_completed / client_notification
            return 'Completed';
        }
        if ($seq <= 4) {             // payment_verification_sample (initial gate)
            return 'Pending Approval';
        }

        return 'In Production';      // seq 5-20: graphic artwork through delivery
    }
}
