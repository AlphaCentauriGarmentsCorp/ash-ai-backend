<?php

namespace App\Services\Storefront;

/**
 * A PayMongo charge never settles inside the request, so the gateway has to hand
 * the SPA something to finish it with: a checkout_url for the e-wallets, a
 * client_key for cards. Carried on a subclass rather than on PaymentResult so
 * the shape OrderController already reads (status, reference, declined()) is
 * untouched — code that does not know about PayMongo keeps working unchanged.
 */
readonly class PayMongoPaymentResult extends PaymentResult
{
    public function __construct(
        string $status,
        ?string $reference = null,
        public ?string $redirectUrl = null,
        public ?string $clientKey = null,
    ) {
        parent::__construct($status, $reference);
    }
}
