<?php

namespace App\Support\Storefront\Stock;

use Illuminate\Support\Facades\DB;

/**
 * The stock-movement engine. This is the file that writes to a live shop's
 * quantities, so it is deliberately small, total, and boring.
 *
 * ---------------------------------------------------------------------------
 * WHAT REPLACED adjustInventory()
 *
 * The source app had one stored number, inventory.available, and moved it by
 * hand at four call sites (order placed, cancelled, reactivated, returned). This
 * schema splits that number three ways and derives the fourth:
 *
 *   on_hand        physically in the warehouse
 *   allocated      spoken for by orders that have not left
 *   cancelled_qty  units an order claimed and then gave back
 *   available      on_hand - allocated, never stored
 *
 * So instead of four hand-written adjustments, every movement is one
 * subtraction between two positions (OrderPipeline::POSITION_VECTOR):
 *
 *     delta = vector(new status) - vector(old status)
 *
 * That is not a simplification for its own sake — it is what makes the movement
 * TOTAL and IDEMPOTENT. Every one of the 8x8 status transitions has a defined
 * answer, no transition needs a was/will-be special case, and setting a status to
 * itself is arithmetically a no-op, so a double-submitted save cannot double-move
 * stock. Checked against the source's behaviour on `available` (= on_hand -
 * allocated), every case agrees:
 *
 *   placed         none -> new         allocated +1        available -1  (as before)
 *   completed      new  -> completed   on_hand -1, alloc -1 available  0  (as before)
 *   cancelled      new  -> cancelled   alloc -1, cancelled +1 available +1 (as before)
 *   un-cancelled   cancelled -> new    alloc +1, cancelled -1 available -1 (as before)
 *   cancel a sale  completed -> cancelled  on_hand +1, cancelled +1 available +1 (as before)
 *   return asked   shipped -> return_requested  on_hand -1, alloc -1 available 0 (as before)
 *   return ok'd    return_requested -> returned  on_hand +1  available +1 (as before)
 *   undo approval  returned -> return_requested on_hand -1  available -1 (as before)
 *
 * ---------------------------------------------------------------------------
 * SAFETY RULES, all of them load-bearing on a live table
 *
 * 1. Every row is read with lockForUpdate() and written from the value that read
 *    returned. The caller must already be inside a transaction.
 * 2. Every result is floored at 0. on_hand, allocated and cancelled_qty are all
 *    UNSIGNED: writing a negative is not "a wrong number", it is
 *    SQLSTATE[22003] and a 500. Flooring also absorbs the one thing this module
 *    cannot know — whether the 15 orders that predate it ever had their
 *    allocation raised at checkout.
 * 3. A line whose SKU no longer resolves to a variant is skipped, exactly as
 *    adjustInventory() returned early on an unknown SKU.
 * 4. Nothing here writes products.is_active or product_variants.is_active. See
 *    the note on autoDeactivate() below — that rule is off unless configured on.
 */
class OrderStock
{
    /** The three quantity columns this module is allowed to move. */
    public const COLUMNS = ['on_hand', 'allocated', 'cancelled_qty'];

    /**
     * Per-unit movement between two ERP statuses.
     *
     * @return array{on_hand: int, allocated: int, cancelled_qty: int}
     */
    public static function delta(string $from, string $to): array
    {
        $fromVector = OrderPipeline::POSITION_VECTOR[OrderPipeline::POSITION[$from] ?? $from] ?? OrderPipeline::POSITION_VECTOR['none'];
        $toVector = OrderPipeline::POSITION_VECTOR[OrderPipeline::POSITION[$to] ?? $to] ?? OrderPipeline::POSITION_VECTOR['none'];

        return [
            'on_hand' => $toVector['on_hand'] - $fromVector['on_hand'],
            'allocated' => $toVector['allocated'] - $fromVector['allocated'],
            'cancelled_qty' => $toVector['cancelled_qty'] - $fromVector['cancelled_qty'],
        ];
    }

    public static function isNoMovement(array $delta): bool
    {
        foreach (self::COLUMNS as $column) {
            if ((int) ($delta[$column] ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply a per-unit delta across an order's lines.
     *
     * @param  array<int, array{variant_id: int|null, sku: string|null, name: string|null, size: string|null, qty: int}>  $lines
     * @param  array{on_hand: int, allocated: int, cancelled_qty: int}  $delta
     * @return int how many variant rows were actually moved
     */
    public static function apply(array $lines, array $delta, string $reason, string $notes, string $user = 'system'): int
    {
        if (self::isNoMovement($delta)) {
            return 0;
        }

        $moved = 0;

        foreach (self::mergeLines($lines) as $line) {
            $variantId = $line['variant_id'] ?? null;
            $qty = (int) ($line['qty'] ?? 0);

            if ($variantId === null || $qty <= 0) {
                continue;
            }

            if (self::move((int) $variantId, $qty, $delta, $reason, $notes, $user)) {
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * The same SKU twice in one order is one movement of the sum — otherwise the
     * second read inside this transaction sees the first write and the log reads
     * as two unrelated adjustments.
     */
    private static function mergeLines(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $variantId = $line['variant_id'] ?? null;
            if ($variantId === null) {
                continue;
            }

            $key = (int) $variantId;
            if (! isset($merged[$key])) {
                $merged[$key] = $line;
                $merged[$key]['qty'] = 0;
            }
            $merged[$key]['qty'] += (int) ($line['qty'] ?? 0);
        }

        return array_values($merged);
    }

    /** One variant row, under lock. Returns false when the row has gone. */
    private static function move(int $variantId, int $qty, array $delta, string $reason, string $notes, string $user): bool
    {
        $variant = DB::table('storefront_product_variants')->where('id', $variantId)->lockForUpdate()->first();

        if ($variant === null) {
            return false;
        }

        $productName = DB::table('storefront_products')->where('id', $variant->product_id)->value('name');

        $updates = [];

        foreach (self::COLUMNS as $column) {
            $step = (int) ($delta[$column] ?? 0) * $qty;
            if ($step === 0) {
                continue;
            }

            $old = (int) $variant->$column;
            // Floor at zero — see safety rule 2. The clamp is recorded honestly:
            // `delta` in the log is the movement that actually happened, not the
            // one that was asked for.
            $new = max(0, $old + $step);

            if ($new === $old) {
                continue;
            }

            $updates[$column] = $new;

            StockActivityLog::write([
                'sku' => $variant->sku,
                'product_name' => $productName,
                'field' => $column,
                'old_value' => (string) $old,
                'new_value' => (string) $new,
                'delta' => (string) ($new - $old),
                'reason' => $reason,
                'notes' => $notes,
                'user' => $user,
            ]);
        }

        if ($updates === []) {
            return false;
        }

        $updates['updated_at'] = now();

        DB::table('storefront_product_variants')->where('id', $variantId)->update($updates);

        self::autoDeactivate($variantId, $updates, $variant, $productName, $reason, $user);

        return true;
    }

    /**
     * The source's auto-deactivate-at-zero / auto-reactivate-on-restock rule,
     * DEFAULT OFF.
     *
     * There it was sound: `available` was a stored column and `active` was the
     * only thing that could hide a sold-out SKU. Here `available` is derived and
     * already reads 0 the moment on_hand meets allocated, so the storefront shows
     * "sold out" without anyone touching a flag — and flipping product_variants
     * .is_active would be a catalogue-visibility change made as a side effect of a
     * fulfilment event, on a live shop, that only a human can undo (the shop's own
     * checkout refuses to reserve an inactive variant).
     *
     * So it is behind a switch. Set stock.orders.auto_deactivate_at_zero to true
     * in config/stock.php to restore the source's behaviour exactly.
     */
    private static function autoDeactivate(int $variantId, array $updates, object $variant, ?string $productName, string $reason, string $user): void
    {
        if (! config('stock.orders.auto_deactivate_at_zero', false)) {
            return;
        }

        if (! array_key_exists('on_hand', $updates)) {
            return;
        }

        $onHand = (int) $updates['on_hand'];
        $wasActive = (bool) $variant->is_active;
        $isActive = $wasActive;

        if ($onHand === 0) {
            $isActive = false;
        } elseif ($onHand > (int) $variant->on_hand && ! $wasActive) {
            $isActive = true;
        }

        if ($isActive === $wasActive) {
            return;
        }

        DB::table('storefront_product_variants')->where('id', $variantId)
            ->update(['is_active' => $isActive ? 1 : 0, 'updated_at' => now()]);

        StockActivityLog::write([
            'sku' => $variant->sku,
            'product_name' => $productName,
            'field' => 'active',
            'old_value' => $wasActive ? 'Active' : 'Inactive',
            'new_value' => $isActive ? 'Active' : 'Inactive',
            'delta' => '',
            'reason' => $reason,
            'notes' => $isActive ? 'Reactivated on restock' : 'Deactivated at zero on hand',
            'user' => $user,
        ]);
    }

    /**
     * An order's lines, resolved to the variant each one actually points at.
     *
     * order_items has no sku column — it snapshots product_id, product_slug, name
     * and size. The SKU lives on product_variants, so a line is matched on
     * (product_id, size), falling back to the slug when product_id was nulled by a
     * catalogue deletion. A line that resolves to nothing keeps its display fields
     * and carries variant_id = null, which OrderStock::apply() skips and the
     * presenter still renders.
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, array<int, array<string, mixed>>> keyed by order id
     */
    public static function linesByOrder(array $orderIds, bool $lock = false): array
    {
        if ($orderIds === []) {
            return [];
        }

        $items = DB::table('storefront_order_items')
            ->whereIn('order_id', $orderIds)
            ->orderBy('order_id')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        $variants = self::variantIndex($items, $lock);

        $byOrder = [];

        foreach ($items as $item) {
            $variant = self::matchVariant($variants, $item);

            $byOrder[(int) $item->order_id][] = [
                'order_item_id' => (int) $item->id,
                'variant_id' => $variant !== null ? (int) $variant->id : null,
                'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                'slug' => $item->product_slug,
                'sku' => $variant->sku ?? null,
                'name' => $item->name,
                'size' => $item->size,
                'qty' => (int) $item->qty,
                'unit_price' => (int) $item->unit_price,
                'line_total' => (int) $item->line_total,
            ];
        }

        return $byOrder;
    }

    /**
     * Variants for a set of order lines, keyed both by product_id|size and by
     * slug|size. Two queries total, never one per line.
     *
     * @return array{by_product: array<string, object>, by_slug: array<string, object>}
     */
    private static function variantIndex(\Illuminate\Support\Collection $items, bool $lock): array
    {
        $productIds = $items->pluck('product_id')->filter()->unique()->values()->all();
        $slugs = $items->pluck('product_slug')->filter()->unique()->values()->all();

        $slugProductIds = $slugs === []
            ? []
            : DB::table('storefront_products')->whereIn('slug', $slugs)->pluck('id', 'slug')->all();

        $allProductIds = array_values(array_unique(array_merge(
            array_map('intval', $productIds),
            array_map('intval', array_values($slugProductIds)),
        )));

        if ($allProductIds === []) {
            return ['by_product' => [], 'by_slug' => []];
        }

        $query = DB::table('storefront_product_variants')->whereIn('product_id', $allProductIds)->orderBy('id');

        // Locked in the same order every time (by id) so two concurrent order
        // saves touching the same design cannot deadlock each other.
        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get();

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[$row->product_id.'|'.strtoupper((string) $row->size)] = $row;
        }

        $bySlug = [];
        foreach ($slugProductIds as $slug => $productId) {
            foreach ($rows as $row) {
                if ((int) $row->product_id === (int) $productId) {
                    $bySlug[$slug.'|'.strtoupper((string) $row->size)] = $row;
                }
            }
        }

        return ['by_product' => $byProduct, 'by_slug' => $bySlug];
    }

    private static function matchVariant(array $index, object $item): ?object
    {
        $size = strtoupper((string) $item->size);

        if ($item->product_id !== null) {
            $hit = $index['by_product'][$item->product_id.'|'.$size] ?? null;
            if ($hit !== null) {
                return $hit;
            }
        }

        return $index['by_slug'][$item->product_slug.'|'.$size] ?? null;
    }

    /**
     * The subset of an order's lines a live return actually covers, with the
     * return's own quantities.
     *
     * Returns in this shop are per-line and partial (return_request_items), where
     * the source restored the whole order on approval. Restoring only what is
     * physically coming back is strictly more correct and never restores more than
     * the source would have. An empty result means the return names no lines, and
     * the caller falls back to the full order.
     *
     * @param  array<int, array<string, mixed>>  $orderLines
     * @return array<int, array<string, mixed>>
     */
    public static function returnLines(int $returnRequestId, array $orderLines): array
    {
        $claims = DB::table('storefront_return_request_items')
            ->where('return_request_id', $returnRequestId)
            ->pluck('qty', 'order_item_id');

        if ($claims->isEmpty()) {
            return [];
        }

        $lines = [];

        foreach ($orderLines as $line) {
            $qty = (int) ($claims[$line['order_item_id']] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $line['qty'] = min($qty, (int) $line['qty']);
            $lines[] = $line;
        }

        return $lines;
    }
}
