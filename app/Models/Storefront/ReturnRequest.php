<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRequest extends Model
{
    protected $table = 'storefront_return_requests';

    /**
     * Statuses that release the quantity they were holding. A return the shop turned
     * down, or the customer took back, must not block a second attempt at the same
     * line — every other status still counts against it.
     */
    public const DEAD_STATUSES = ['cancelled', 'rejected'];

    // order_id, user_id and reference come from the resolved order and the id,
    // never from client input.
    protected $fillable = [
        'status', 'reason', 'note', 'requested_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** The SPA only ever holds the reference — row ids are not in the API surface. */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function getReasonLabelAttribute(): string
    {
        return config('reefer.returns.reasons.'.$this->reason)
            ?? ucfirst(str_replace('_', ' ', (string) $this->reason));
    }

    /**
     * What the customer is owed, in whole pesos. Summed from the order lines on every
     * read rather than stored, so it cannot drift from what was actually paid.
     *
     * The order's discount has to come off. order_items.unit_price is the FULL catalog
     * price — OrderController prices the lines first and PricingService takes the
     * voucher off the order total afterwards — so summing the lines on a discounted
     * order would promise back more than the buyer ever handed over. A 50%-off order
     * would refund double. The discount is apportioned by each line's share of the
     * order subtotal, which is the only split that stays consistent when a buyer
     * returns some lines now and the rest later.
     *
     * Shipping is deliberately excluded; the acknowledgement email says so.
     */
    public function refundSubtotal(): int
    {
        $gross = (int) $this->items->sum(fn (ReturnRequestItem $item) => $item->lineTotal());

        $order = $this->order;
        $discount = (int) ($order->discount_amount ?? 0);
        $orderSubtotal = (int) ($order->subtotal ?? 0);

        if ($discount <= 0 || $orderSubtotal <= 0) {
            return $gross;
        }

        // Round the customer's way on the half-peso: a refund that is one peso light
        // is a complaint, one peso heavy is not.
        $share = (int) ceil($gross * $discount / $orderSubtotal);

        return max(0, $gross - $share);
    }
}
