<?php

namespace App\Models\Storefront;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    // The ERP owns `orders` (production orders). Customer orders live here.
    protected $table = 'storefront_orders';

    protected $fillable = [
        'order_number', 'user_id', 'email', 'ship_to_name', 'phone',
        'street', 'barangay', 'city', 'province', 'region', 'postal',
        'shipping_method', 'subtotal', 'discount_code', 'discount_amount',
        'shipping_fee', 'total',
        'payment_method', 'payment_status', 'payment_ref',
        'status', 'stage', 'courier', 'tracking_number', 'eta', 'placed_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'shipping_fee' => 'integer',
            'total' => 'integer',
            'stage' => 'integer',
            'eta' => 'date',
            'placed_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id');
    }

    /** Human label for the current fulfilment stage. */
    public function getStageLabelAttribute(): string
    {
        return config('reefer.stages')[$this->stage] ?? 'Ordered';
    }

    /**
     * When the return window shuts, or null if this order can never be returned.
     *
     * Single source of truth for the rule: ReturnRequestController enforces it and
     * OrderResource publishes it, so the button and the endpoint can never disagree
     * about whether a return is still possible.
     */
    public function returnsCloseOn(): ?Carbon
    {
        $stages = (array) config('reefer.stages');

        if (config('reefer.returns.require_delivered') && (int) $this->stage !== count($stages) - 1) {
            return null;
        }

        $placed = $this->placed_at ?? $this->created_at;

        $anchor = config('reefer.returns.window_from') === 'delivery'
            ? ($this->delivered_at ?? $placed)
            : $placed;

        return $anchor?->copy()->startOfDay()->addDays((int) config('reefer.returns.window_days'))->endOfDay();
    }

    /** Can a return be opened right now? */
    public function isReturnable(): bool
    {
        $closes = $this->returnsCloseOn();

        return $closes !== null && now()->lessThanOrEqualTo($closes);
    }

    /** Labels for the receipt. The API keeps sending the raw keys. */
    public function getPaymentMethodLabelAttribute(): string
    {
        return match ($this->payment_method) {
            'gcash' => 'GCash',
            'maya' => 'Maya',
            'card' => 'Card',
            'cod' => 'Cash on Delivery',
            default => strtoupper((string) $this->payment_method),
        };
    }

    public function getShippingMethodLabelAttribute(): string
    {
        return config('reefer.shipping_methods.'.$this->shipping_method.'.label') ?? 'Shipping';
    }
}
