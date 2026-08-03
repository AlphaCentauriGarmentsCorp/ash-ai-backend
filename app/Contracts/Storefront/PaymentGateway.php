<?php

namespace App\Contracts\Storefront;

use App\Services\Storefront\PaymentResult;

/**
 * The single seam between checkout and money. The implementation decides the
 * outcome; the caller never does. Going live is a binding swap in
 * StorefrontServiceProvider and nothing else — order code does not move.
 */
interface PaymentGateway
{
    /**
     * @param  string  $method  One of config('reefer.payment_methods').
     * @param  int  $amount  Whole pesos, always recomputed server-side from the
     *                       catalog — never a client-supplied total.
     * @param  string|null  $simulate  Demo hook for the checkout page's "SIMULATE
     *                                 FAILURE" button. Honoured only outside
     *                                 production, so a deployed build cannot be
     *                                 talked into a fake outcome by the client.
     */
    public function charge(string $method, int $amount, ?string $simulate = null): PaymentResult;
}
