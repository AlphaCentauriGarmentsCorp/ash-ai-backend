<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Model;

/**
 * A voucher code.
 *
 * Everything that decides whether a code may be used, and what it is worth, lives
 * here — so the preview endpoint and checkout ask the same object the same
 * questions and cannot answer them differently.
 */
class Discount extends Model
{
    protected $table = 'storefront_discounts';

    protected $fillable = [
        'code', 'type', 'value', 'min_subtotal', 'starts_at', 'ends_at',
        'max_uses', 'used_count', 'per_user_limit', 'is_active', 'description',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'min_subtotal' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'per_user_limit' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** Codes are shouted, not typed: wave10 and WAVE10 are the same voucher. */
    public static function normalize(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    /** Normalising on write is what lets the unique index carry the whole rule. */
    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = static::normalize($value);
    }

    /**
     * What this code takes off, in whole pesos.
     *
     * Never more than the basket it comes off: a ₱500 code on a ₱300 order is ₱300
     * off, not a ₱200 refund. Percent codes are additionally capped by
     * reefer.discounts.max_percent, so a mis-seeded "90% off" cannot empty a large
     * basket.
     */
    public function discountFor(int $subtotal): int
    {
        if ($subtotal <= 0) {
            return 0;
        }

        $amount = match ($this->type) {
            'percent' => (int) floor($subtotal * min($this->value, (int) config('reefer.discounts.max_percent')) / 100),
            'fixed' => $this->value,
            default => 0,
        };

        return max(0, min($amount, $subtotal));
    }

    public function isRedeemableBy(?Customer $user, int $subtotal): bool
    {
        return $this->rejectionFor($user, $subtotal) === null;
    }

    /**
     * null when the shopper may use this code, otherwise the reason they cannot —
     * written for them, not for us. Every message contains the word "code": the
     * checkout page decides a 422 is about the voucher by reading it.
     */
    public function rejectionFor(?Customer $user, int $subtotal): ?string
    {
        if (! $this->is_active) {
            return 'That code is no longer active.';
        }

        if ($this->starts_at && now()->lt($this->starts_at)) {
            return 'That code is not live yet.';
        }

        if ($this->ends_at && now()->gt($this->ends_at)) {
            return 'That code has expired.';
        }

        if ($subtotal < $this->min_subtotal) {
            return 'That code needs a subtotal of ₱'.number_format($this->min_subtotal)
                .' — yours is ₱'.number_format($subtotal).'.';
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'That code has been fully claimed.';
        }

        if ($this->per_user_limit !== null) {
            // No account means no way to count, and a per-account limit nobody is
            // counted against is not a limit.
            if (! $user) {
                return 'Sign in to use that code.';
            }

            if ($this->redemptionsBy($user) >= $this->per_user_limit) {
                return $this->per_user_limit === 1
                    ? 'You have already used that code.'
                    : 'You have used that code as many times as it allows.';
            }
        }

        return null;
    }

    /**
     * How many orders this account has already put this code on. Counted from the
     * orders table rather than a tally column: orders are the only record of a
     * redemption actually happening.
     */
    public function redemptionsBy(?Customer $user): int
    {
        if (! $user) {
            return 0;
        }

        return Order::query()
            ->where('user_id', $user->id)
            ->where('discount_code', $this->code)
            ->count();
    }
}
