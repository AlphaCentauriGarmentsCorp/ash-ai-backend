<?php

namespace App\Services\Storefront;

use InvalidArgumentException;

class PricingService
{
    /**
     * The whole money breakdown in one call, so checkout and the voucher preview
     * cannot disagree about a total.
     *
     * Free shipping is judged on the PRE-discount subtotal, deliberately: a voucher
     * is a thank-you, not a reason to take the free delivery back off the same
     * order. A ₱2,600 basket keeps free express even after ₱260 comes off it.
     *
     * @param  string|null  $method  null = not chosen yet (the preview endpoint),
     *                               which quotes merchandise only.
     * @return array{subtotal: int, discount: int, shipping_fee: int, total: int}
     */
    public function quote(int $subtotal, ?string $method, int $discount = 0): array
    {
        // Clamped here as well as in Discount::discountFor — this is the last gate
        // before a total, and a negative one must be impossible to construct.
        $discount = max(0, min($discount, $subtotal));
        $shippingFee = $method ? $this->shippingFee($subtotal, $method) : 0;

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping_fee' => $shippingFee,
            'total' => $subtotal - $discount + $shippingFee,
        ];
    }

    public function shippingFee(int $subtotal, string $method): int
    {
        $config = config('reefer.shipping_methods.'.$method);

        // Returning 0 for an unknown method would hand out free shipping on a typo.
        // Rule::in on the request should stop this ever firing.
        if (! $config) {
            throw new InvalidArgumentException("Unknown shipping method [$method].");
        }

        $fee = (int) $config['fee'];

        // Read the per-method flag rather than special-casing method names, so a new
        // shipping method behaves the way its own config says it should.
        if (($config['free_over_threshold'] ?? false)
            && $subtotal >= (int) config('reefer.free_ship_threshold')) {
            return 0;
        }

        return $fee;
    }
}
