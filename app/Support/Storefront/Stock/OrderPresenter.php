<?php

namespace App\Support\Storefront\Stock;

use Illuminate\Support\Facades\DB;

/**
 * Turns this shop's orders into the row shape the Stock manager's UI reads —
 * the port of OrdersController::presentOrder(), except that here a "row" is a
 * join of orders + order_items + product_variants + return_requests rather than
 * one flat table.
 *
 * Key names are the UI's, not this schema's, because Orders.jsx, Dashboard.jsx,
 * Catalog.jsx and Scan.jsx are moving over unchanged:
 *
 *   order_id       <- orders.order_number   (the RFR-PH0019005 the shop already
 *                                            mints; PUT /orders/{orderId} takes it)
 *   customer_name  <- orders.ship_to_name
 *   product        <- first line's name, + " +N more"
 *   sku            <- first line's variant SKU
 *   qty            <- SUM(order_items.qty)
 *   total          <- orders.total, cast to float (the UI does arithmetic on it)
 *   address        <- the shipping snapshot, joined back into one line
 *   items[]        <- one entry per order_items row
 *
 * Richer keys the shop has and the ERP did not (stage, subtotal, discount,
 * payment_status, the structured address, the live return) are ADDED. Nothing the
 * UI reads is renamed or dropped.
 *
 * THE STAGE DATES. The source stamped in_process_date / to_pickup_date /
 * shipped_date on the order row; this schema has no such columns and this module
 * may not add any. Three of the seven are recoverable from the shop itself —
 * order_date from placed_at, completed_date from delivered_at,
 * return_requested_date / returned_date from the return_requests timestamps — and
 * the rest are read back out of the audit log, which records every transition this
 * module performs. Before this module has moved a given order, those three read
 * null and the UI's stageDate() falls back down its own list, exactly as it does
 * for an order that skipped a stage.
 */
class OrderPresenter
{
    /**
     * Present every order, newest first — GET /api/stocks/orders.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $orders = DB::table('storefront_orders')
            ->orderByDesc(DB::raw('COALESCE(placed_at, created_at)'))
            ->orderByDesc('id')
            ->get();

        return self::presentMany($orders->all());
    }

    /** One order by its order_number, or null. */
    public static function one(string $orderNumber): ?array
    {
        $order = DB::table('storefront_orders')->where('order_number', $orderNumber)->first();

        if ($order === null) {
            return null;
        }

        $rows = self::presentMany([$order]);

        return $rows[0] ?? null;
    }

    /**
     * @param  array<int, object>  $orders
     * @return array<int, array<string, mixed>>
     */
    public static function presentMany(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $orderIds = array_map(fn ($order) => (int) $order->id, $orders);
        $orderNumbers = array_map(fn ($order) => (string) $order->order_number, $orders);

        $linesByOrder = OrderStock::linesByOrder($orderIds);
        $returnsByOrder = self::liveReturns($orderIds);
        $logDates = self::stageDatesFromLog($orderNumbers);

        $out = [];

        foreach ($orders as $order) {
            $out[] = self::present(
                $order,
                $linesByOrder[(int) $order->id] ?? [],
                $returnsByOrder[(int) $order->id] ?? null,
                $logDates[(string) $order->order_number] ?? [],
            );
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, string>  $logDates
     */
    public static function present(object $order, array $lines, ?object $liveReturn, array $logDates = []): array
    {
        $status = OrderPipeline::statusFor($order->status ?? null, (int) ($order->stage ?? 0), $liveReturn);

        $items = array_map(fn (array $line) => [
            // The six keys the waybill, the search box and the report all read.
            'sku' => $line['sku'] ?? '',
            'product' => $line['name'],
            'size' => $line['size'] ?? '',
            'qty' => (int) $line['qty'],
            'price' => (float) $line['unit_price'],
            'line_total' => (float) $line['line_total'],
            // Additive: what this shop knows and the ERP's items JSON never did.
            'order_item_id' => $line['order_item_id'],
            'variant_id' => $line['variant_id'],
            'product_id' => $line['product_id'],
            'slug' => $line['slug'],
        ], $lines);

        $totalQty = array_sum(array_column($items, 'qty'));
        $first = $items[0] ?? null;
        $extra = max(0, count($items) - 1);

        $product = $first === null
            ? '—'
            : ($extra > 0 ? $first['product'].' +'.$extra.' more' : $first['product']);

        $orderDate = self::date($order->placed_at ?? null) ?? self::date($order->created_at ?? null);

        $dates = [
            'order_date' => $orderDate,
            'in_process_date' => $logDates['in_process'] ?? null,
            'to_pickup_date' => $logDates['to_pickup'] ?? null,
            'shipped_date' => $logDates['shipped'] ?? null,
            // delivered_at is the shop's own arrival stamp and outranks the log.
            'completed_date' => self::date($order->delivered_at ?? null) ?? ($logDates['completed'] ?? null),
            'return_requested_date' => null,
            'returned_date' => null,
        ];

        if ($liveReturn !== null) {
            $dates['return_requested_date'] = self::date($liveReturn->requested_at ?? null)
                ?? ($logDates['return_requested'] ?? null);

            if (in_array(strtolower((string) $liveReturn->status), OrderPipeline::RETURN_SETTLED, true)) {
                $dates['returned_date'] = self::date($liveReturn->resolved_at ?? null)
                    ?? ($logDates['returned'] ?? null);
            }
        }

        return array_merge([
            'id' => (int) $order->id,
            'order_id' => (string) $order->order_number,
            'customer_name' => $order->ship_to_name,
            'product' => $product,
            'sku' => $first['sku'] ?? '',
            'qty' => (int) $totalQty,
            'status' => $status,
            'tracking_number' => $order->tracking_number,
            'courier' => $order->courier,
            'total' => (float) $order->total,
            'email' => $order->email,
            'phone' => $order->phone,
            'address' => self::address($order),
            'payment_method' => $order->payment_method,
            // The source carried the WEBSITE's order number here, because the two
            // systems had separate numbering. One database, one number: order_id
            // already is the shop's own, so there is no second id to report.
            'external_order_id' => null,
            'items' => $items,
        ], $dates, [
            // ---- additive: the shop's own vocabulary, for anything that wants it
            'order_number' => (string) $order->order_number,
            'shop_status' => $order->status,
            'stage' => (int) ($order->stage ?? 0),
            'stage_label' => (string) (((array) config('reefer.stages', []))[(int) ($order->stage ?? 0)] ?? ''),
            'allocated_status' => OrderPipeline::isAllocated($status),
            'user_id' => $order->user_id !== null ? (int) $order->user_id : null,
            'subtotal' => (float) ($order->subtotal ?? 0),
            'shipping_fee' => (float) ($order->shipping_fee ?? 0),
            'discount_code' => $order->discount_code ?? null,
            'discount_amount' => (float) ($order->discount_amount ?? 0),
            'shipping_method' => $order->shipping_method ?? null,
            'payment_status' => $order->payment_status ?? null,
            'payment_ref' => $order->payment_ref ?? null,
            'eta' => self::date($order->eta ?? null),
            'delivered_at' => self::date($order->delivered_at ?? null),
            'placed_at' => $order->placed_at ?? null,
            'ship_to' => [
                'name' => $order->ship_to_name,
                'phone' => $order->phone,
                'street' => $order->street ?? null,
                'barangay' => $order->barangay ?? null,
                'city' => $order->city ?? null,
                'province' => $order->province ?? null,
                'region' => $order->region ?? null,
                'postal' => $order->postal ?? null,
            ],
            'return_request' => $liveReturn === null ? null : [
                'reference' => $liveReturn->reference,
                'status' => $liveReturn->status,
                'reason' => $liveReturn->reason,
                'note' => $liveReturn->note ?? null,
                'requested_at' => $liveReturn->requested_at ?? null,
                'resolved_at' => $liveReturn->resolved_at ?? null,
            ],
        ]);
    }

    /**
     * The newest live return per order. Rejected and cancelled returns gave their
     * claim back (App\Models\ReturnRequest::DEAD_STATUSES), so they never decide an
     * order's ERP status — an order whose return was rejected reads as whatever
     * pipeline stage it is sitting at, which is what the Orders screen's Reject
     * button promises.
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, object>
     */
    public static function liveReturns(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = DB::table('storefront_return_requests')
            ->whereIn('order_id', $orderIds)
            ->whereNotIn('status', OrderPipeline::RETURN_DEAD)
            ->orderBy('order_id')
            ->orderBy('id')
            ->get();

        $byOrder = [];

        // Ascending, so the last write per order is the newest row.
        foreach ($rows as $row) {
            $byOrder[(int) $row->order_id] = $row;
        }

        return $byOrder;
    }

    /**
     * The first time each order entered each ERP status, read back out of the
     * audit log. See the class note on why this exists.
     *
     * @param  array<int, string>  $orderNumbers
     * @return array<string, array<string, string>>
     */
    public static function stageDatesFromLog(array $orderNumbers): array
    {
        if ($orderNumbers === []) {
            return [];
        }

        $time = StockActivityLog::TIME_COLUMN;

        $rows = DB::table(StockActivityLog::TABLE)
            ->select('sku', 'new_value', DB::raw('MIN(`'.$time.'`) as first_at'))
            ->where('field', OrderPipeline::LOG_FIELD_STATUS)
            ->whereIn('sku', $orderNumbers)
            ->groupBy('sku', 'new_value')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $date = self::logDate($row->first_at);

            if ($date !== null) {
                $out[(string) $row->sku][(string) $row->new_value] = $date;
            }
        }

        return $out;
    }

    /**
     * The shipping snapshot as one line, in Philippine postal order. The waybill
     * pulls a 4-digit postcode and a destination city back out of this string, so
     * the postcode stays last and the parts stay comma-separated.
     */
    public static function address(object $order): ?string
    {
        $parts = array_values(array_filter([
            $order->street ?? null,
            $order->barangay ?? null,
            $order->city ?? null,
            $order->province ?? null,
            $order->region ?? null,
        ], fn ($part) => trim((string) $part) !== ''));

        if ($parts === []) {
            return null;
        }

        $line = implode(', ', $parts);
        $postal = trim((string) ($order->postal ?? ''));

        return $postal !== '' ? $line.' '.$postal : $line;
    }

    /**
     * An Activity Log stamp, as a date.
     *
     * NOT converted, unlike date(). The log's `timestamp` is filled by the
     * database's own DEFAULT CURRENT_TIMESTAMP, so it is already in the MySQL
     * server's local time and is stored naive; running it through a UTC → Manila
     * conversion would push every stage date eight hours forward and land the
     * evening ones on the following day. Orders' placed_at / delivered_at are
     * real Laravel timestamps and DO need converting — hence two functions.
     * (This is the same timezone seam the source app carried between its
     * DB-stamped log and its Manila-stamped order dates.)
     */
    public static function logDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Business dates are Manila dates, same as the source stamped them, so the
     * "days in stage" counters and the report windows read the same day the
     * warehouse does.
     */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->timezone('Asia/Manila')->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
