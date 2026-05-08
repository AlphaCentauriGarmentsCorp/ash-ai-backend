<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 3 — JSON shape for a Material Request returned to the frontend.
 */
class MaterialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'mr_code' => $this->mr_code,
            'status'  => $this->status,
            'reason'  => $this->reason,
            'rejection_reason' => $this->rejection_reason,

            'order_id' => $this->order_id,
            'order'    => $this->whenLoaded('order', fn () => [
                'id'           => $this->order->id,
                'po_code'      => $this->order->po_code,
                'client_brand' => $this->order->client_brand,
                'client_name'  => $this->order->client_name,
            ]),

            'stage_id' => $this->stage_id,
            'stage'    => $this->whenLoaded('stage', fn () => [
                'id'       => $this->stage->id,
                'stage'    => $this->stage->stage,
                'sequence' => $this->stage->sequence,
                'status'   => $this->stage->status,
            ]),

            'requested_by_user_id' => $this->requested_by_user_id,
            'requested_by'         => $this->whenLoaded('requestedBy', fn () => [
                'id'   => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ]),

            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_by'         => $this->whenLoaded('approvedBy', fn () => [
                'id'   => $this->approvedBy?->id,
                'name' => $this->approvedBy?->name,
            ]),
            'approved_at'         => $this->approved_at?->toDateTimeString(),

            'purchase_request_id' => $this->purchase_request_id,
            'purchase_request'    => $this->whenLoaded('purchaseRequest', fn () => $this->purchaseRequest ? [
                'id'      => $this->purchaseRequest->id,
                'pr_code' => $this->purchaseRequest->pr_code,
                'status'  => $this->purchaseRequest->status,
            ] : null),

            'items' => $this->whenLoaded('items', fn () =>
                $this->items->map(function ($item) {
                    return [
                        'id'                 => $item->id,
                        'material_id'        => $item->material_id,
                        'material'           => $item->relationLoaded('material') && $item->material ? [
                            'id'   => $item->material->id,
                            'name' => $item->material->name,
                            'unit' => $item->material->unit,
                            'stock_on_hand' => $item->material->stock_on_hand,
                        ] : null,
                        'quantity_requested' => $item->quantity_requested,
                        'quantity_available' => $item->quantity_available,
                        'quantity_short'     => $item->quantity_short,
                        'unit'               => $item->unit,
                        'notes'              => $item->notes,
                    ];
                }),
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
