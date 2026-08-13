<?php

namespace App\Support\Storefront\Stock;

/**
 * The Product Catalog's payload: the same rows the Inventory grid serves,
 * regrouped one-per-DESIGN and joined to what each size has actually sold.
 *
 * WHAT THIS CLASS IS NOT. It is not a second remap. Every row here comes from
 * InventoryData — the module's single translation between "an ERP inventory row"
 * and (products JOIN product_variants) — so the Catalog and the Inventory grid
 * cannot drift into two spellings of one SKU. Only the two things the Catalog
 * screen needs and the Inventory grid does not live here:
 *
 *   * the design grouping (groupCatalog() in Catalog.jsx, server-side), and
 *   * per-SKU completed sales, which the screen currently derives client-side by
 *     walking the whole orders feed.
 *
 * Both are additive: Catalog.jsx keeps working unchanged against the flat
 * /inventory feed. This is what a caller uses when it wants the screen's payload
 * in one request instead of three.
 *
 * SALES ARE KEYED BY SKU, not by product name — each size is its own SKU, and
 * blending them would make the per-size units and revenue meaningless. Same rule
 * as buildSalesLookup() in the source page, and it is stricter here: the source
 * could only read the order's summary sku/qty/total columns, so a multi-item
 * order credited everything to its FIRST line. This walks the real line items.
 */
class CatalogPresenter
{
    /**
     * "Pending" = reserved but not yet a confirmed sale. Mirrors PENDING_STATUSES
     * in Catalog.jsx, which is OrderPipeline::ALLOCATED_STATUSES by another name —
     * cancelled orders never counted, since their stock went back to Inventory.
     */
    public const PENDING_STATUSES = OrderPipeline::ALLOCATED_STATUSES;

    /** The flat presented rows, website content attached — the /inventory shape. */
    public static function all(): array
    {
        return InventoryData::attachWebsiteContent(InventoryData::presentedList());
    }

    /**
     * Completed units and revenue per SKU, from order LINE ITEMS.
     *
     * @param  array<int, array<string, mixed>>  $orders  presented orders
     * @return array<string, array{units: int, revenue: float}>
     */
    public static function salesBySku(array $orders): array
    {
        $sales = [];

        foreach ($orders as $order) {
            if (($order['status'] ?? '') !== 'completed') {
                continue;
            }

            foreach ($order['items'] ?? [] as $item) {
                $sku = (string) ($item['sku'] ?? '');

                if ($sku === '') {
                    continue;
                }

                if (! isset($sales[$sku])) {
                    $sales[$sku] = ['units' => 0, 'revenue' => 0.0];
                }

                $sales[$sku]['units'] += (int) ($item['qty'] ?? 0);
                $sales[$sku]['revenue'] += (float) ($item['line_total'] ?? 0);
            }
        }

        return $sales;
    }

    /**
     * One entry per design, sizes in wearable order, each size carrying its own
     * completed sales. Port of groupCatalog().
     *
     * The grouping key is the product_code, falling back to the name — exactly
     * what the page does. products.product_code is nullable and arrived without a
     * backfill, so most rows have none today and group by name instead; the moment
     * a code is set, that wins. Nothing is written to make it so.
     *
     * @param  array<int, array<string, mixed>>  $rows  presented catalogue rows
     * @param  array<string, array{units: int, revenue: float}>  $sales
     * @return array<int, array<string, mixed>>
     */
    public static function groupByDesign(array $rows, array $sales = []): array
    {
        $designs = [];

        foreach ($rows as $row) {
            $code = (string) ($row['product_code'] ?? '');
            $key = $code !== '' ? $code : (string) $row['name'];

            if (! isset($designs[$key])) {
                $designs[$key] = [
                    'key' => $key,
                    'product_code' => $code,
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'image' => $row['image'],
                    'website' => $row['website'] ?? null,
                    // Additive: what the screen would otherwise dig out of sizes[0].
                    'product_id' => $row['product_id'] ?? null,
                    'slug' => $row['slug'] ?? null,
                    'audience' => $row['audience'] ?? null,
                    'price' => $row['price'] ?? null,
                    'image_url' => $row['image_url'] ?? null,
                    'product_active' => $row['product_active'] ?? null,
                    'sizes' => [],
                ];
            }

            $sale = $sales[$row['sku']] ?? ['units' => 0, 'revenue' => 0.0];

            $designs[$key]['sizes'][] = [
                'row' => $row,
                'sku' => $row['sku'],
                'size' => ($row['size'] ?? '') !== '' ? $row['size'] : '—',
                'units' => (int) $sale['units'],
                'revenue' => (float) $sale['revenue'],
            ];
        }

        $designs = array_values($designs);

        foreach ($designs as &$design) {
            usort(
                $design['sizes'],
                fn ($a, $b) => SizeOrder::sizeRank($a['size']) <=> SizeOrder::sizeRank($b['size'])
                    ?: strcmp((string) $a['sku'], (string) $b['sku']),
            );

            // Design-level totals the cards show, so the client does not re-add
            // them on every render.
            $design['total_available'] = array_sum(array_map(fn ($s) => (int) $s['row']['available'], $design['sizes']));
            $design['total_allocated'] = array_sum(array_map(fn ($s) => (int) $s['row']['order_allocated'], $design['sizes']));
            $design['units_sold'] = array_sum(array_map(fn ($s) => $s['units'], $design['sizes']));
            $design['revenue'] = array_sum(array_map(fn ($s) => $s['revenue'], $design['sizes']));
            $design['active_sizes'] = count(array_filter($design['sizes'], fn ($s) => (bool) $s['row']['active']));
        }
        unset($design);

        return $designs;
    }

    /**
     * The four KPI tiles at the top of the Catalog screen, computed exactly as the
     * page computes them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $orders
     */
    public static function kpis(array $rows, array $orders): array
    {
        $completed = array_filter($orders, fn ($o) => ($o['status'] ?? '') === 'completed');
        $pending = array_filter($orders, fn ($o) => in_array($o['status'] ?? '', self::PENDING_STATUSES, true));

        return [
            'activeCount' => count(array_filter($rows, fn ($r) => ($r['active'] ?? false) !== false)),
            'totalUnits' => array_sum(array_map(fn ($o) => (int) ($o['qty'] ?? 0), $completed)),
            'totalRevenue' => array_sum(array_map(fn ($o) => (float) ($o['total'] ?? 0), $completed)),
            'pipelineRevenue' => array_sum(array_map(fn ($o) => (float) ($o['total'] ?? 0), $pending)),
        ];
    }

    /** Every category present in the catalogue, sorted — the filter dropdown. */
    public static function categories(array $rows): array
    {
        $categories = array_values(array_unique(array_map(
            fn ($r) => (string) ($r['category'] ?? ''),
            $rows,
        )));

        sort($categories);

        return $categories;
    }
}
