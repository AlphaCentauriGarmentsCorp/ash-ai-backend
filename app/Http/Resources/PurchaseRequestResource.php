<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 3 — JSON shape for a Purchase Request returned to the frontend.
 */
class PurchaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'pr_code' => $this->pr_code,
            'status'  => $this->status,
            'reason'  => $this->reason,
            'total_amount' => $this->total_amount,

            'order_id' => $this->order_id,
            'order'    => $this->whenLoaded('order', fn () => [
                'id'           => $this->order->id,
                'po_code'      => $this->order->po_code,
                'client_brand' => $this->order->client_brand,
                'client_name'  => $this->order->client_name,
            ]),

            'material_request_id' => $this->material_request_id,
            'material_request'    => $this->whenLoaded('materialRequest', fn () => $this->materialRequest ? [
                'id'      => $this->materialRequest->id,
                'mr_code' => $this->materialRequest->mr_code,
                'status'  => $this->materialRequest->status,
            ] : null),

            'supplier_id' => $this->supplier_id,
            'supplier'    => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id'   => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),

            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_by'         => $this->whenLoaded('approvedBy', fn () => [
                'id'   => $this->approvedBy?->id,
                'name' => $this->approvedBy?->name,
            ]),
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'ordered_at'  => $this->ordered_at?->toDateTimeString(),
            'received_at' => $this->received_at?->toDateTimeString(),

            'items' => $this->whenLoaded('items', fn () =>
                $this->items->map(function ($item) {
                    return [
                        'id'          => $item->id,
                        'material_id' => $item->material_id,
                        'material'    => $item->relationLoaded('material') && $item->material ? [
                            'id'   => $item->material->id,
                            'name' => $item->material->name,
                            'unit' => $item->material->unit,
                        ] : null,
                        'quantity'    => $item->quantity,
                        'unit_price'  => $item->unit_price,
                        'line_total'  => $item->line_total,
                        'unit'        => $item->unit,
                        'notes'       => $item->notes,
                    ];
                }),
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
