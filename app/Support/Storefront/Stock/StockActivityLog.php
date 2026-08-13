<?php

namespace App\Support\Storefront\Stock;

use App\Models\Storefront\Stock\InventoryLog;

/**
 * The order domain's seam onto the Activity Log (stock_inventory_log).
 *
 * There is exactly ONE writer for that table — InventoryData::logMovement, which
 * clamps every value to its column width and lets the database stamp the row —
 * and this class delegates to it rather than opening a second INSERT with its own
 * conventions. Two writers would be two timestamp conventions, and the dates the
 * Orders screen reads back out of this table would be eight hours out from the
 * ones the Inventory screen writes into it.
 *
 * What lives here instead is the READ side the order module needs, which the
 * inventory module has no use for: the log is where this schema keeps the three
 * stage dates it has no columns for (see OrderPresenter).
 *
 * The rows the order module writes, and why they are in a table named for
 * inventory: the source's own inventory_log carried exactly the same two kinds —
 * OrdersController wrote 'Reserved for order REEF-#####' rows into it from
 * adjustInventory(), attributed to 'system'. An order that moves stock IS an
 * inventory movement.
 *
 *   field = 'on_hand' | 'allocated' | 'cancelled_qty'
 *       one per variant per movement; sku is the variant's SKU.
 *   field = 'order_status'
 *       one per transition; sku is the ORDER NUMBER, which is the same
 *       dual-purpose the sku column already has for per-design content rows.
 */
class StockActivityLog
{
    public const TABLE = 'storefront_stock_inventory_log';

    /** The audit stamp. Filled by the database (DEFAULT CURRENT_TIMESTAMP). */
    public const TIME_COLUMN = 'timestamp';

    /**
     * Append one audit row.
     *
     * @param  array{sku: string, product_name?: string|null, field: string, old_value?: mixed, new_value?: mixed, delta?: mixed, reason: string, notes?: string, user?: string}  $entry
     */
    public static function write(array $entry): void
    {
        InventoryData::logMovement($entry);
    }

    /** Rows for one order number, newest first — the order's own history. */
    public static function forOrder(string $orderNumber): array
    {
        return InventoryLog::query()
            ->where('sku', $orderNumber)
            ->orderByDesc(self::TIME_COLUMN)
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
