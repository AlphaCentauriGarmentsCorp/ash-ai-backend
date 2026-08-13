<?php

namespace App\Support\Storefront\Stock;

/**
 * The date-range analytics report — port of OrdersController::report()'s
 * aggregation half, rule for rule.
 *
 * Only the inputs changed: `orders` rows come from OrderPresenter (so `status`
 * is the ERP vocabulary and `items` is already an array) and `inventory` rows
 * from CatalogPresenter. Every figure below is computed exactly as the source
 * computed it, including the ones that look like they should agree and do not:
 *
 *   * revenue is recognised on COMPLETION, not placement, and the completed set
 *     is filtered on the completion date rather than the order date;
 *   * units exclude cancelled orders but include everything else in flight;
 *   * Stage Activity needs every order's stage dates, not just the ones placed
 *     inside the window, so both `orders` (windowed) and `allOrders` are carried.
 *
 * Building the workbook is a separate job — see XlsxWriter.
 */
class OrderReport
{
    /** The day an order's value LEAVES the pipeline, or null while in flight. */
    public static function pipelineExitDate(array $order): ?string
    {
        if ($order['status'] === 'completed') {
            return (string) ($order['completed_date'] ?? $order['order_date'] ?? '');
        }

        if ($order['status'] === 'cancelled') {
            return (string) ($order['shipped_date'] ?? $order['to_pickup_date'] ?? $order['in_process_date'] ?? $order['order_date'] ?? '');
        }

        if ($order['status'] === 'returned') {
            return (string) ($order['returned_date'] ?? $order['completed_date'] ?? $order['shipped_date'] ?? $order['order_date'] ?? '');
        }

        return null;
    }

    public static function orderItems(array $order): array
    {
        if (is_array($order['items'] ?? null) && count($order['items']) > 0) {
            return $order['items'];
        }

        $qty = (int) ($order['qty'] ?? 0);
        $total = (float) ($order['total'] ?? 0);

        return [[
            'sku' => $order['sku'] ?? '',
            'product' => $order['product'] ?? '—',
            'size' => '',
            'qty' => $qty,
            'price' => $qty !== 0 ? $total / $qty : $total,
            'line_total' => $total,
        ]];
    }

    public static function eachDayInclusive(string $start, string $end): array
    {
        $days = [];
        $cur = strtotime($start.'T00:00:00UTC');
        $last = strtotime($end.'T00:00:00UTC');

        if ($cur === false || $last === false || $cur > $last) {
            return $days;
        }

        while ($cur <= $last && count($days) < 400) {
            $days[] = gmdate('Y-m-d', $cur);
            $cur = strtotime('+1 day', $cur);
        }

        return $days;
    }

    public static function daysBetween(string $fromISO, string $toISO): ?int
    {
        $a = strtotime($fromISO.'T00:00:00UTC');
        $b = strtotime($toISO.'T00:00:00UTC');

        if ($a === false || $b === false) {
            return null;
        }

        return (int) round(($b - $a) / 86400);
    }

    /**
     * @param  array<int, array<string, mixed>>  $allOrders  every presented order
     * @param  array<int, array<string, mixed>>  $inventory  every presented catalogue row
     */
    public static function build(array $allOrders, array $inventory, string $start, string $end, string $generatedBy): array
    {
        // Orders placed inside the window.
        $rows = array_values(array_filter(
            $allOrders,
            fn ($r) => (string) ($r['order_date'] ?? '') >= $start && (string) ($r['order_date'] ?? '') <= $end,
        ));

        // Inventory is a live snapshot, not a windowed figure.
        usort($inventory, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name'])
            ?: strcmp((string) $a['sku'], (string) $b['sku']));

        $completionDate = fn (array $r) => (string) ($r['completed_date'] ?? $r['order_date'] ?? '');

        $completed = array_values(array_filter($allOrders, function ($r) use ($completionDate, $start, $end) {
            if ($r['status'] !== 'completed') {
                return false;
            }
            $d = $completionDate($r);

            return $d >= $start && $d <= $end;
        }));

        $revenue = array_sum(array_map(fn ($r) => (float) $r['total'], $completed));
        $units = array_sum(array_map(
            fn ($r) => (int) $r['qty'],
            array_filter($rows, fn ($r) => $r['status'] !== 'cancelled'),
        ));

        $statusCounts = [];
        foreach ($rows as $r) {
            $statusCounts[$r['status']] = ($statusCounts[$r['status']] ?? 0) + 1;
        }

        $cancelledInRange = array_values(array_filter($rows, fn ($r) => $r['status'] === 'cancelled'));
        $cancelledValue = array_sum(array_map(fn ($r) => (float) $r['total'], $cancelledInRange));

        // ---- per-SKU / per-product rollup, from LINE ITEMS ----
        $productAgg = [];
        $bucket = function (string $key, array $item) use (&$productAgg): void {
            if (! isset($productAgg[$key])) {
                $productAgg[$key] = [
                    'product' => $item['product'] ?? '—', 'sku' => $item['sku'] ?? '—',
                    'size' => $item['size'] ?? '', 'units' => 0, 'completed_revenue' => 0.0, 'orders' => 0,
                ];
            }
        };

        $orderItemRows = [];

        foreach ($rows as $r) {
            foreach (self::orderItems($r) as $it) {
                $lineTotal = isset($it['line_total'])
                    ? (float) $it['line_total']
                    : ((float) ($it['price'] ?? 0)) * ((int) ($it['qty'] ?? 0));

                $orderItemRows[] = [
                    'order_id' => $r['order_id'], 'order_date' => $r['order_date'], 'status' => $r['status'],
                    'customer_name' => $r['customer_name'], 'courier' => $r['courier'],
                    'product' => $it['product'] ?? '—', 'sku' => $it['sku'] ?? '—', 'size' => $it['size'] ?? '',
                    'qty' => (int) ($it['qty'] ?? 0), 'price' => (float) ($it['price'] ?? 0),
                    'line_total' => $lineTotal,
                ];

                if ($r['status'] === 'cancelled') {
                    continue;
                }

                $key = ($it['sku'] ?? '') !== '' ? $it['sku'] : ($it['product'] ?? '—');
                $bucket($key, $it);
                $productAgg[$key]['units'] += (int) ($it['qty'] ?? 0);
                $productAgg[$key]['orders'] += 1;
            }
        }

        foreach ($completed as $r) {
            foreach (self::orderItems($r) as $it) {
                $lineTotal = isset($it['line_total'])
                    ? (float) $it['line_total']
                    : ((float) ($it['price'] ?? 0)) * ((int) ($it['qty'] ?? 0));
                $key = ($it['sku'] ?? '') !== '' ? $it['sku'] : ($it['product'] ?? '—');
                $bucket($key, $it);
                $productAgg[$key]['completed_revenue'] += $lineTotal;
            }
        }

        $byProduct = array_values($productAgg);
        usort($byProduct, fn ($a, $b) => $b['units'] <=> $a['units']);

        // ---- daily finance ledger ----
        $windowDays = self::eachDayInclusive($start, $end);

        $daily = array_map(function ($day) use ($rows, $completed, $allOrders, $completionDate) {
            $placedToday = array_filter($rows, fn ($r) => $r['order_date'] === $day);
            $completedToday = array_filter($completed, fn ($r) => $completionDate($r) === $day);

            $pipeIn = array_sum(array_map(
                fn ($r) => (float) $r['total'],
                array_filter($allOrders, fn ($r) => (string) ($r['order_date'] ?? '') === $day),
            ));
            $pipeOut = array_sum(array_map(
                fn ($r) => (float) $r['total'],
                array_filter($allOrders, fn ($r) => self::pipelineExitDate($r) === $day),
            ));

            $pipeBalance = 0.0;
            foreach ($allOrders as $r) {
                $entered = (string) ($r['order_date'] ?? '');
                if ($entered === '' || $entered > $day) {
                    continue;
                }
                $exited = self::pipelineExitDate($r);
                if ($exited !== null && $exited !== '' && $exited <= $day) {
                    continue;
                }
                $pipeBalance += (float) $r['total'];
            }

            return [
                'date' => $day,
                'orders' => count($placedToday),
                'units' => array_sum(array_map(
                    fn ($r) => (int) $r['qty'],
                    array_filter($placedToday, fn ($r) => $r['status'] !== 'cancelled'),
                )),
                'completed_orders' => count($completedToday),
                'completed_revenue' => array_sum(array_map(fn ($r) => (float) $r['total'], $completedToday)),
                'pipeline_in' => $pipeIn,
                'pipeline_out' => $pipeOut,
                'pipeline_balance' => $pipeBalance,
            ];
        }, $windowDays);

        // ---- courier performance ----
        $courierAgg = [];

        foreach ($rows as $r) {
            $key = ($r['courier'] ?? '') !== '' && $r['courier'] !== null ? $r['courier'] : 'Unassigned';

            if (! isset($courierAgg[$key])) {
                $courierAgg[$key] = ['courier' => $key, 'orders' => 0, 'completed' => 0, 'cancelled' => 0,
                    'revenue' => 0.0, 'units' => 0, 'turnaroundDays' => []];
            }

            $courierAgg[$key]['orders'] += 1;

            if ($r['status'] === 'cancelled') {
                $courierAgg[$key]['cancelled'] += 1;
            } else {
                $courierAgg[$key]['units'] += (int) $r['qty'];
            }

            if ($r['status'] === 'completed') {
                $courierAgg[$key]['completed'] += 1;
                $courierAgg[$key]['revenue'] += (float) $r['total'];
                $d = self::daysBetween((string) $r['order_date'], $completionDate($r));
                if ($d !== null && $d >= 0) {
                    $courierAgg[$key]['turnaroundDays'][] = $d;
                }
            }
        }

        $byCourier = array_map(function ($c) {
            $avg = count($c['turnaroundDays']) > 0
                ? array_sum($c['turnaroundDays']) / count($c['turnaroundDays'])
                : null;

            return [
                'courier' => $c['courier'], 'orders' => $c['orders'], 'completed' => $c['completed'],
                'cancelled' => $c['cancelled'], 'units' => $c['units'], 'revenue' => $c['revenue'],
                'avg_turnaround' => $avg === null ? null : round($avg * 10) / 10,
                'completion_rate' => $c['orders'] > 0 ? $c['completed'] / $c['orders'] : 0,
            ];
        }, array_values($courierAgg));

        usort($byCourier, fn ($a, $b) => $b['orders'] <=> $a['orders']);

        // ---- inventory: stock, value, alerts, movement ----
        $soldInRange = [];
        $lastSaleBySku = [];

        foreach ($allOrders as $r) {
            if ($r['status'] === 'cancelled') {
                continue;
            }

            $d = (string) ($r['order_date'] ?? '');

            foreach (self::orderItems($r) as $it) {
                $sku = $it['sku'] ?? '';
                if ($sku === '' || $sku === null) {
                    continue;
                }
                if (! isset($lastSaleBySku[$sku]) || $d > $lastSaleBySku[$sku]) {
                    $lastSaleBySku[$sku] = $d;
                }
                if ($d >= $start && $d <= $end) {
                    $soldInRange[$sku] = ($soldInRange[$sku] ?? 0) + (int) ($it['qty'] ?? 0);
                }
            }
        }

        $todayISO = now('Asia/Manila')->format('Y-m-d');

        $movementTier = function (?int $days): string {
            if ($days === null) {
                return 'dead';
            }
            if ($days <= 7) {
                return 'fast';
            }
            if ($days <= 14) {
                return 'moderate';
            }
            if ($days <= 60) {
                return 'slow';
            }

            return 'dead';
        };

        $inventoryRows = array_map(function ($p) use ($lastSaleBySku, $soldInRange, $todayISO, $movementTier) {
            $last = $lastSaleBySku[$p['sku']] ?? '';
            $since = $last !== '' ? self::daysBetween($last, $todayISO) : null;
            $available = (int) $p['available'];
            $price = (float) $p['price'];

            return [
                'sku' => $p['sku'], 'name' => $p['name'], 'category' => $p['category'],
                'size' => $p['size'] ?? '', 'product_code' => $p['product_code'] ?? '',
                'location' => $p['location'] ?? '', 'warehouse' => $p['warehouse'] ?? '',
                'area' => $p['area'] ?? '',
                'price' => $price, 'available' => $available, 'stock_value' => $price * $available,
                'active' => $p['active'] ? 'Active' : 'Inactive',
                'units_sold_in_range' => $soldInRange[$p['sku']] ?? 0,
                'last_sale' => $last, 'days_since_last_sale' => $since,
                'movement' => $movementTier($since),
                'stock_state' => $available === 0 ? 'Out of stock' : ($available <= 5 ? 'Low stock' : 'Healthy'),
            ];
        }, $inventory);

        $stockValue = array_sum(array_column($inventoryRows, 'stock_value'));
        $outOfStock = array_values(array_filter($inventoryRows, fn ($p) => $p['available'] === 0));
        $lowStock = array_values(array_filter($inventoryRows, fn ($p) => $p['available'] > 0 && $p['available'] <= 5));
        $deadStock = array_values(array_filter($inventoryRows, fn ($p) => $p['movement'] === 'dead'));
        $deadStockValue = array_sum(array_column($deadStock, 'stock_value'));

        $dayCount = $start <= $end ? (self::daysBetween($start, $end) ?? -1) + 1 : 0;

        $closingPipeline = count($daily) > 0 ? $daily[count($daily) - 1]['pipeline_balance'] : 0;
        $peakPipeline = array_reduce($daily, fn ($m, $d) => max($m, $d['pipeline_balance']), 0);

        return [
            'start' => $start, 'end' => $end, 'dayCount' => $dayCount,
            'generatedBy' => $generatedBy,
            'generatedAt' => now('Asia/Manila')->format('M j, Y g:i A'),
            'orders' => $rows,
            'allOrders' => $allOrders,
            'totalOrders' => count($rows),
            'completedCount' => count($completed),
            'revenue' => $revenue, 'units' => $units,
            'avgOrderValue' => count($completed) > 0 ? round($revenue / count($completed)) : 0,
            'statusCounts' => $statusCounts, 'byProduct' => $byProduct, 'daily' => $daily,
            'cancelledCount' => count($cancelledInRange), 'cancelledValue' => $cancelledValue,
            'closingPipeline' => $closingPipeline, 'peakPipeline' => $peakPipeline,
            'orderItemRows' => $orderItemRows, 'byCourier' => $byCourier,
            'inventoryRows' => $inventoryRows, 'stockValue' => $stockValue,
            'outOfStock' => $outOfStock, 'lowStock' => $lowStock,
            'deadStock' => $deadStock, 'deadStockValue' => $deadStockValue,
            'skuCount' => count($inventoryRows),
        ];
    }

    /**
     * The report as sheets of rows — the same sheet set the source's workbook had
     * (FINANCE · ORDERS · INVENTORY), flattened to header + data rows so a writer
     * can render it without knowing anything about the figures.
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    public static function sheets(array $data): array
    {
        $statusLabels = [
            'new' => 'New', 'in_process' => 'In Process', 'to_pickup' => 'To Pickup',
            'shipped' => 'Shipped', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
            'return_requested' => 'Return Requested', 'returned' => 'Returned',
        ];

        $movementLabels = [
            'fast' => 'Fast Moving (0-7d)', 'moderate' => 'Moderate (8-14d)',
            'slow' => 'Slow (15-60d)', 'dead' => 'Dead Stock (61d+)',
        ];

        $sheets = [];

        $sheets['Summary'] = array_merge([
            ['REEFER — Stock manager report'],
            ['Range', $data['start'].' → '.$data['end'], $data['dayCount'].' day(s)'],
            ['Generated', $data['generatedAt'], 'by '.$data['generatedBy']],
            [],
            ['Metric', 'Value'],
            ['Orders placed', $data['totalOrders']],
            ['Orders completed', $data['completedCount']],
            ['Completed revenue', $data['revenue']],
            ['Average order value', $data['avgOrderValue']],
            ['Units (excl. cancelled)', $data['units']],
            ['Cancelled orders', $data['cancelledCount']],
            ['Cancelled value', $data['cancelledValue']],
            ['Closing pipeline', $data['closingPipeline']],
            ['Peak pipeline', $data['peakPipeline']],
            ['SKUs', $data['skuCount']],
            ['Stock value', $data['stockValue']],
            ['Dead stock value', $data['deadStockValue']],
            [],
            ['Status', 'Orders'],
        ], array_map(
            fn ($status, $count) => [$statusLabels[$status] ?? $status, $count],
            array_keys($data['statusCounts']),
            array_values($data['statusCounts']),
        ));

        $sheets['Finance Daily'] = array_merge(
            [['Date', 'Orders', 'Units', 'Completed orders', 'Completed revenue', 'Pipeline in', 'Pipeline out', 'Pipeline balance']],
            array_map(fn ($d) => [
                $d['date'], $d['orders'], $d['units'], $d['completed_orders'],
                $d['completed_revenue'], $d['pipeline_in'], $d['pipeline_out'], $d['pipeline_balance'],
            ], $data['daily']),
        );

        $sheets['Top Products'] = array_merge(
            [['Product', 'SKU', 'Size', 'Units', 'Orders', 'Completed revenue']],
            array_map(fn ($p) => [
                $p['product'], $p['sku'], $p['size'], $p['units'], $p['orders'], $p['completed_revenue'],
            ], $data['byProduct']),
        );

        $sheets['Orders'] = array_merge(
            [['Order ID', 'Date', 'Status', 'Customer', 'Courier', 'Tracking', 'Product', 'Qty', 'Total', 'Payment']],
            array_map(fn ($o) => [
                $o['order_id'], $o['order_date'], $statusLabels[$o['status']] ?? $o['status'],
                $o['customer_name'], $o['courier'], $o['tracking_number'],
                $o['product'], $o['qty'], $o['total'], $o['payment_method'],
            ], $data['orders']),
        );

        $sheets['Order Items'] = array_merge(
            [['Order ID', 'Date', 'Status', 'Customer', 'Courier', 'Product', 'SKU', 'Size', 'Qty', 'Price', 'Line total']],
            array_map(fn ($r) => [
                $r['order_id'], $r['order_date'], $statusLabels[$r['status']] ?? $r['status'],
                $r['customer_name'], $r['courier'], $r['product'], $r['sku'], $r['size'],
                $r['qty'], $r['price'], $r['line_total'],
            ], $data['orderItemRows']),
        );

        $sheets['Courier'] = array_merge(
            [['Courier', 'Orders', 'Completed', 'Cancelled', 'Units', 'Revenue', 'Avg turnaround (days)', 'Completion rate']],
            array_map(fn ($c) => [
                $c['courier'], $c['orders'], $c['completed'], $c['cancelled'], $c['units'],
                $c['revenue'], $c['avg_turnaround'], $c['completion_rate'],
            ], $data['byCourier']),
        );

        $sheets['Inventory'] = array_merge(
            [['SKU', 'Name', 'Category', 'Size', 'Product code', 'Location', 'Warehouse', 'Area', 'Price', 'Available', 'Stock value', 'Status', 'Units sold in range', 'Last sale', 'Days since', 'Movement', 'Stock state']],
            array_map(fn ($p) => [
                $p['sku'], $p['name'], $p['category'], $p['size'], $p['product_code'],
                $p['location'], $p['warehouse'], $p['area'], $p['price'], $p['available'],
                $p['stock_value'], $p['active'], $p['units_sold_in_range'], $p['last_sale'],
                $p['days_since_last_sale'], $movementLabels[$p['movement']] ?? $p['movement'], $p['stock_state'],
            ], $data['inventoryRows']),
        );

        $sheets['Stock Alerts'] = array_merge(
            [['Alert', 'SKU', 'Name', 'Size', 'Available', 'Stock value']],
            array_map(fn ($p) => ['Out of stock', $p['sku'], $p['name'], $p['size'], $p['available'], $p['stock_value']], $data['outOfStock']),
            array_map(fn ($p) => ['Low stock', $p['sku'], $p['name'], $p['size'], $p['available'], $p['stock_value']], $data['lowStock']),
        );

        $sheets['Movement (FSN)'] = array_merge(
            [['Movement', 'SKU', 'Name', 'Size', 'Available', 'Units sold in range', 'Last sale', 'Days since', 'Stock value']],
            array_map(fn ($p) => [
                $movementLabels[$p['movement']] ?? $p['movement'], $p['sku'], $p['name'], $p['size'],
                $p['available'], $p['units_sold_in_range'], $p['last_sale'], $p['days_since_last_sale'], $p['stock_value'],
            ], $data['inventoryRows']),
        );

        return $sheets;
    }
}
