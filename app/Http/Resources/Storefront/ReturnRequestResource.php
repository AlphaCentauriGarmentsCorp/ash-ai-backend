<?php

namespace App\Http\Resources\Storefront;

use App\Models\Storefront\ReturnRequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Storefront\ReturnRequest
 */
class ReturnRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $refund = $this->refundSubtotal();

        return [
            'reference' => $this->reference,
            'status' => $this->status,
            // The raw key for logic, the label for the page — same split the rest of
            // the API uses for stages and shipping methods.
            'reason' => $this->reason,
            'reason_label' => $this->reason_label,
            'note' => $this->note,
            'requested_at' => optional($this->requested_at)->toIso8601String(),
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),

            // What the customer knows the order as.
            'order_number' => $this->order?->order_number,

            // Recomputed from the stored order lines on every read; the client never
            // sends an amount and there is none on the row to send back.
            'refund_subtotal' => $refund,
            'refund_subtotal_formatted' => '₱'.number_format($refund),

            'items' => $this->items->map(fn (ReturnRequestItem $item) => [
                'slug' => $item->orderItem?->product_slug,
                'name' => $item->orderItem?->name,
                'size' => $item->orderItem?->size,
                'qty' => $item->qty,
                'unit_price' => (int) ($item->orderItem?->unit_price ?? 0),
                'line_total' => $item->lineTotal(),
            ])->values(),
        ];
    }
}
