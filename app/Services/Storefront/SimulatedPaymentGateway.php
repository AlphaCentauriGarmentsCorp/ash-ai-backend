<?php

namespace App\Services\Storefront;

use App\Contracts\Storefront\PaymentGateway;
use Illuminate\Support\Str;

/**
 * Stand-in for PayMongo. Everything here is fake, but the shape is real: the
 * caller hands over an amount and a method and gets back a status it did not
 * choose. This stays the default; PayMongoGateway takes over the moment a
 * secret key is configured, and the checkout flow around it does not change.
 */
class SimulatedPaymentGateway implements PaymentGateway
{
    /**
     * @param  string|null  $simulate  Demo hook for the checkout page's "SIMULATE
     *                                 FAILURE" button. Honoured only outside
     *                                 production, so a deployed build cannot be
     *                                 talked into a fake outcome by the client.
     */
    public function charge(string $method, int $amount, ?string $simulate = null): PaymentResult
    {
        // Cash on delivery collects no money now; it stays pending until fulfilment.
        if ($method === 'cod') {
            return new PaymentResult('pending');
        }

        if ($simulate === 'fail' && ! app()->isProduction()) {
            return new PaymentResult('declined');
        }

        return new PaymentResult('paid', 'SIM-'.strtoupper(Str::random(12)));
    }
}
