<?php

namespace App\Services\Storefront;

readonly class PaymentResult
{
    public function __construct(
        public string $status,
        public ?string $reference = null,
    ) {
    }

    public function declined(): bool
    {
        return $this->status === 'declined';
    }
}
