<?php

namespace App\Services\Storefront;

use App\Models\Storefront\Customer;
use App\Models\Storefront\Discount;
use Illuminate\Validation\ValidationException;

/**
 * The one road from a typed code to money. Both the preview endpoint and checkout
 * come through here, so the number the shopper is quoted and the number they are
 * charged cannot drift apart.
 */
class DiscountService
{
    /** A quote for the checkout page. Nothing is held or spent. */
    public function preview(?string $code, ?Customer $user, int $subtotal): ?Discount
    {
        return $this->resolve($code, $user, $subtotal, 'code', lock: false);
    }

    /**
     * Checkout's copy. MUST run inside a transaction: the row lock is held until
     * commit, so two shoppers racing for the last use of a code serialize here and
     * the loser is told it is spent, instead of both being waved through a
     * read-then-write gap.
     */
    public function lockForCheckout(?string $code, ?Customer $user, int $subtotal): ?Discount
    {
        return $this->resolve($code, $user, $subtotal, 'discount_code', lock: true);
    }

    /**
     * Spend one use. Called only once the order row exists: a declined payment
     * returns out of the transaction rather than throwing, so an increment taken
     * any earlier would commit and burn a use on an order that never happened.
     */
    public function markRedeemed(?Discount $discount): void
    {
        $discount?->increment('used_count');
    }

    /**
     * @param  string  $field  Which key the 422 hangs off — 'code' when the shopper
     *                         is trying one out, 'discount_code' at checkout, where
     *                         it matches the field they submitted.
     */
    private function resolve(?string $code, ?Customer $user, int $subtotal, string $field, bool $lock): ?Discount
    {
        if (blank($code)) {
            return null;
        }

        $query = Discount::query()->where('code', Discount::normalize($code));

        if ($lock) {
            $query->lockForUpdate();
        }

        $discount = $query->first();

        if (! $discount) {
            throw ValidationException::withMessages([
                $field => 'We do not recognise that code.',
            ]);
        }

        // Refuse loudly rather than quietly dropping the code: the shopper agreed
        // to a discounted total, and charging them a different one is worse than
        // sending them back to the summary.
        if ($reason = $discount->rejectionFor($user, $subtotal)) {
            throw ValidationException::withMessages([$field => $reason]);
        }

        return $discount;
    }
}
