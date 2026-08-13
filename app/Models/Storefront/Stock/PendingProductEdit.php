<?php

namespace App\Models\Storefront\Stock;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued, not-yet-applied edit awaiting the Push Product review gate.
 *
 * @property int $id
 * @property string $sku            product_variants.sku, or products.product_code
 * @property string|null $product_name
 * @property string $field          'price' | 'active' | 'available' | a content field
 * @property string $old_value      the LIVE value when the edit was queued
 * @property string $new_value
 * @property string|null $reason    mandatory for On Hand edits
 * @property string|null $notes
 * @property string $edited_by
 * @property string $edited_at      'Y-m-d H:i:s' STRING, stamped Asia/Manila
 *
 * ONE ROW PER (sku, field), enforced by a unique index. Queueing the same field
 * twice must UPDATE the existing row, not insert beside it — last edit wins,
 * and old_value keeps pointing at the value that is still live. Use
 * updateOrCreate() (or an upsert) keyed on ['sku', 'field'], never create().
 *
 * Both timestamps are strings on purpose:
 *
 *   - `edited_at` is NOT cast to a datetime. It is stamped in Asia/Manila by
 *     the queueing code rather than by the DB clock, and Catalog.jsx shows it
 *     verbatim. A datetime cast would re-interpret it in the app timezone and
 *     shift the "Queued by X at …" tooltips.
 *
 *   - `old_value` / `new_value` are NOT cast to anything. They are canonical
 *     strings — 'active' is '1'/'0', price is a float rendered as a string,
 *     content fields are free text — and the frontend compares them as strings
 *     (pending.new_value === '1'). Casting price to a float here would make
 *     that comparison silently false for the status column.
 *
 * The value vocabulary (which fields may be queued, how each is normalised,
 * which are per-design content fields) lives in App\Support\Storefront\Stock\
 * PendingProductEdits, not here — one source of truth, and the controllers
 * already go through it.
 */
class PendingProductEdit extends Model
{
    protected $table = 'storefront_stock_pending_product_edits';

    /**
     * No created_at / updated_at columns — `edited_at` is the stamp, and it is
     * supplied explicitly rather than by Eloquent so it lands in Asia/Manila.
     */
    public $timestamps = false;

    protected $fillable = [
        'sku', 'product_name', 'field', 'old_value', 'new_value',
        'reason', 'notes', 'edited_by', 'edited_at',
    ];

    /** The queue as the Push Product modal lists it: newest edit first. */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('edited_at')->orderByDesc('id');
    }

    /** Every pending row for one SKU or product_code. */
    public function scopeForSku(Builder $query, string $sku): Builder
    {
        return $query->where('sku', $sku);
    }

    /**
     * The exact row a re-edit would overwrite. Mirrors the unique index.
     */
    public function scopeForField(Builder $query, string $sku, string $field): Builder
    {
        return $query->where('sku', $sku)->where('field', $field);
    }

    /**
     * The variant this edit targets, for the per-variant fields.
     *
     * Unconstrained like the log's: apply-time deliberately DISCARDS a pending
     * row whose SKU has vanished rather than failing the batch, so a null here
     * is a documented state — "the product went away before the push ran" —
     * not an integrity error.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductVariant::class, 'sku', 'sku');
    }

    /** The design this edit targets, for the website-content fields. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class, 'sku', 'product_code');
    }
}
