<?php

namespace App\Models\Storefront\Stock;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One row of the Activity Log: a single field change, already made.
 *
 * @property int $id
 * @property string $sku            product_variants.sku, or products.product_code
 * @property string|null $product_name
 * @property string $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $delta          signed number AS A STRING, '' when not numeric
 * @property string $reason
 * @property string $notes
 * @property string $user           'system' for order-driven movement
 * @property string $timestamp      'Y-m-d H:i:s' STRING, not a Carbon date
 *
 * APPEND-ONLY. Nothing updates or deletes a row here; a correction is another
 * row. That is why there is no updated_at and why `timestamp` has a plain
 * DEFAULT CURRENT_TIMESTAMP with no ON UPDATE.
 *
 * `timestamp` is deliberately NOT cast to a datetime. Inventory.jsx renders it
 * as String(log.timestamp).replace('T', ' ') — with the raw MySQL string that
 * replace is a no-op and the cell reads "2026-08-13 09:14:02". A datetime cast
 * makes toArray() emit "2026-08-13T09:14:02.000000Z", so the replace leaves a
 * trailing ".000000Z" in every row of the table. Leave it a string.
 *
 * Likewise `delta` stays a string: writers pass '' for non-numeric fields (a
 * name change has no delta) and both consumers re-parse with Number(). Casting
 * it to an integer turns '' into 0 and paints a "(0)" badge on every text edit.
 */
class InventoryLog extends Model
{
    protected $table = 'storefront_stock_inventory_log';

    /**
     * No created_at / updated_at columns exist — `timestamp` is the audit stamp
     * and the database fills it. Leaving this true would make every insert fail
     * on an unknown column.
     */
    public $timestamps = false;

    /**
     * The nine columns a writer supplies. `id` and `timestamp` are the only
     * others and both self-populate.
     */
    protected $fillable = [
        'sku', 'product_name', 'field', 'old_value', 'new_value',
        'delta', 'reason', 'notes', 'user',
    ];

    /**
     * The human-facing id the UI shows: LOG-1001, LOG-1002, …
     *
     * Computed in SQL rather than as an accessor so it survives ->toArray(),
     * ->get()->pluck('log_id'), the xlsx export and any raw DB read, and so it
     * matches the original Node implementation byte for byte. Chain it onto any
     * query whose rows are going to the frontend:
     *
     *     InventoryLog::withLogId()->orderByDesc('timestamp')->get()
     *
     * Selecting '*' alongside is what keeps every other column present.
     */
    public function scopeWithLogId(Builder $query): Builder
    {
        return $query->select('*', DB::raw("CONCAT('LOG-', 1000 + id) AS log_id"));
    }

    /** The Inventory detail panel's per-SKU history, newest first. */
    public function scopeForSku(Builder $query, string $sku): Builder
    {
        return $query->where('sku', $sku)->orderByDesc('timestamp')->orderByDesc('id');
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('timestamp')->orderByDesc('id');
    }

    /**
     * The variant this row is about, when there is one.
     *
     * A plain relation, NOT a foreign key: the column is unconstrained on
     * purpose (see the migration) because a log row must outlive the variant it
     * describes — the 'deleted' row is written in the same transaction that
     * removes the variant — and because for website-content fields this column
     * holds a products.product_code instead, in which case this resolves to
     * null and `product()` is the one to use.
     *
     * Eager-load it only when you have checked the field is a per-variant one;
     * on a mixed page it will be null for the content rows and that is correct,
     * not a bug.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductVariant::class, 'sku', 'sku');
    }

    /** The design this row is about, for the website-content fields. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class, 'sku', 'product_code');
    }
}
