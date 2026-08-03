<?php

namespace App\Models\Storefront;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $product_variant_id
 * @property \Illuminate\Support\Carbon|null $notified_at
 */
class StockAlert extends Model
{
    protected $table = 'storefront_stock_alerts';

    // Nothing is fillable: user_id comes from the bearer token and
    // product_variant_id from a slug/size the controller resolved against the
    // catalog. Neither is ever client input, so mass assignment has no job here.
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Still waiting on a restock — the notifier has not mailed about it yet. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('notified_at');
    }

    public function isPending(): bool
    {
        return $this->notified_at === null;
    }
}
