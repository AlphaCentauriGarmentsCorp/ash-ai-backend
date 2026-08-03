<?php

namespace App\Http\Resources\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status,
            'stage' => $this->stage,
            'stage_label' => $this->stage_label,
            // Both: 'date' for the design's fixed format, 'placed_at' for clients
            // that want to format it in the viewer's own locale.
            'date' => optional($this->placed_at)->format('n/j/y'),
            'placed_at' => optional($this->placed_at)->toIso8601String(),

            'email' => $this->email,
            'ship_to' => [
                'name' => $this->ship_to_name,
                'phone' => $this->phone,
                'street' => $this->street,
                'barangay' => $this->barangay,
                'city' => $this->city,
                'province' => $this->province,
                'region' => $this->region,
                'postal' => $this->postal,
            ],

            'shipping_method' => $this->shipping_method,
            'subtotal' => $this->subtotal,
            // What was redeemed, snapshotted on the order — null and 0 when no code
            // was used, so the summary can render the line unconditionally.
            'discount_code' => $this->discount_code,
            'discount_amount' => $this->discount_amount,
            'discount_amount_formatted' => '₱' . number_format($this->discount_amount),
            'shipping_fee' => $this->shipping_fee,
            'total' => $this->total,
            'total_formatted' => '₱' . number_format($this->total),

            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            // The buyer's own reference for this charge; null for COD.
            'payment_ref' => $this->payment_ref,

            'courier' => $this->courier,
            'tracking_number' => $this->tracking_number,
            'eta' => optional($this->eta)->format('M j'),
            'delivered_at' => optional($this->delivered_at)->toIso8601String(),

            /*
             * Whether a return can be opened, decided here so the UI never has to
             * re-derive the rule and drift from it. The endpoint re-checks all of this
             * on POST regardless — this is for showing the button, not for enforcing.
             */
            'can_return' => $this->isReturnable(),
            'returns_close_on' => optional($this->returnsCloseOn())->format('M j, Y'),
            'return_window_days' => (int) config('reefer.returns.window_days'),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'slug' => $i->product_slug,
                'name' => $i->name,
                'size' => $i->size,
                'unit_price' => $i->unit_price,
                'qty' => $i->qty,
                'line_total' => $i->line_total,
            ])->values()),
        ];
    }
}
