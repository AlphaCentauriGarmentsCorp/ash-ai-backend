<?php

namespace App\Http\Controllers\Storefront\Stock;

use App\Http\Controllers\Controller;
use App\Support\Storefront\Stock\CatalogPresenter;
use App\Support\Storefront\Stock\InventoryData;
use App\Support\Storefront\Stock\OrderPresenter;
use App\Support\Storefront\Stock\PendingProductEdits;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Product Catalog surface — the design-level view of the same catalogue the
 * Inventory grid serves per SKU, plus the website copy the Catalog's Website View
 * edits.
 *
 *   GET /api/stocks/catalog                    designs + KPIs + categories
 *   GET /api/stocks/catalog/rows               the flat per-SKU rows
 *   GET /api/stocks/catalog/design?key=…       one design, sizes and sales
 *   GET /api/stocks/catalog/design/content?key=…   its 14 website-content fields
 *
 * ---------------------------------------------------------------------------
 * READ-ONLY, ON PURPOSE. There is no PUT here, and that is not an omission.
 *
 * In the source, every human edit to price, status and website copy went through
 * ONE door — the Push Product queue (pending_product_edits) — and was applied by
 * the midnight batch or a Force Push. That queue is one of the three tables this
 * module keeps, its controller is the write path, and Catalog.jsx already posts
 * every edit to it. A second, direct write path on this controller would be a way
 * to change a live product while the Push Product modal still shows the old value
 * queued against it, which is precisely the confusion the queue exists to prevent.
 *
 * So: this controller answers questions, PendingEditsController takes the edits,
 * and applying a queued row is what writes to `products`.
 *
 * ---------------------------------------------------------------------------
 * WHAT HAPPENED TO product_content. The source kept the storefront's copy in its
 * own table and pushed it across a database boundary. There is no boundary here:
 * products.blurb IS the copy the storefront renders, so a push is live on the
 * site the moment it applies rather than after a sync. Six of the fourteen fields
 * have no column in this schema (color, print_method, care, origin, image_back,
 * image_detail) — they are reported here, by name, in `unsupported_fields`, and
 * they read back as null rather than disappearing, so the Website View renders
 * unchanged and falls back to the site's own defaults for them.
 */
class CatalogController extends Controller
{
    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }

    /**
     * GET /catalog — the whole screen's payload in one request.
     *
     * Catalog.jsx builds this client-side today from GET /inventory + GET /orders
     * and keeps working exactly as it is. This is for anything that would rather
     * not re-implement groupCatalog() and buildSalesLookup().
     */
    public function index(Request $request)
    {
        $rows = CatalogPresenter::all();
        $orders = OrderPresenter::all();
        $sales = CatalogPresenter::salesBySku($orders);

        $designs = CatalogPresenter::groupByDesign($rows, $sales);

        $category = trim((string) $request->query('category', ''));

        if ($category !== '' && $category !== 'all') {
            $designs = array_values(array_filter($designs, fn ($d) => $d['category'] === $category));
        }

        $search = mb_strtolower(trim((string) $request->query('search', '')));

        if ($search !== '') {
            $designs = array_values(array_filter(
                $designs,
                fn ($d) => str_contains(mb_strtolower((string) $d['name']), $search),
            ));
        }

        return response()->json([
            'designs' => $designs,
            'kpis' => CatalogPresenter::kpis($rows, $orders),
            'categories' => CatalogPresenter::categories($rows),
            'count' => count($rows),
            'design_count' => count($designs),
            'unsupported_content_fields' => $this->unsupportedContentFields(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /catalog/rows — the flat per-SKU rows, website content attached.
     *
     * Byte for byte what GET /api/stocks/inventory serves, from the same
     * InventoryData call, so a client can use either and never see two spellings
     * of one SKU.
     */
    public function rows()
    {
        return response()->json(CatalogPresenter::all());
    }

    /** GET /catalog/design?key=… (or /catalog/design/{design}) */
    public function design(Request $request, ?string $design = null)
    {
        $key = $this->designKey($request, $design);
        $rows = $this->rowsForDesign($key);

        $orders = OrderPresenter::all();
        $sales = CatalogPresenter::salesBySku($orders);

        // rowsForDesign() has already 404'd on an unknown key, so the grouping of
        // a non-empty row set always yields exactly one design.
        $grouped = CatalogPresenter::groupByDesign($rows, $sales);

        return response()->json($grouped[0]);
    }

    /**
     * GET /catalog/design/content?key=… — the Website View's payload for one
     * design: all fourteen fields, plus which of them this database can store.
     */
    public function content(Request $request, ?string $design = null)
    {
        $key = $this->designKey($request, $design);
        $rows = $this->rowsForDesign($key);
        $first = $rows[0];

        $product = DB::table('storefront_products')->where('id', $first['product_id'])->first();

        if ($product === null) {
            $this->fail(404, 'Design not found: '.$key);
        }

        return response()->json([
            'product_code' => $first['product_code'],
            'product_id' => (int) $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'category' => $first['category'],
            'website' => InventoryData::websiteFor($product),
            'fields' => PendingProductEdits::CONTENT_FIELDS,
            'supported_fields' => PendingProductEdits::SUPPORTED_CONTENT_FIELDS,
            'unsupported_fields' => $this->unsupportedContentFields(),
            // audience and type are NOT NULL on products, so unlike every other
            // content field they cannot be cleared back to "site keeps its own".
            'required_fields' => PendingProductEdits::REQUIRED_CONTENT_FIELDS,
            'audiences' => PendingProductEdits::CONTENT_AUDIENCES,
            'types' => PendingProductEdits::CONTENT_TYPES,
            'colors' => PendingProductEdits::CONTENT_COLORS,
            // Every edit goes through the queue — see the class note.
            'edit_endpoint' => 'POST /api/stocks/inventory/pending-edits',
            'sizes' => array_map(fn ($row) => [
                'sku' => $row['sku'],
                'size' => $row['size'],
                'available' => $row['available'],
                'active' => $row['active'],
            ], $rows),
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * The design key, from the path or from ?key=.
     *
     * Two ways in because the key is a product_code when the row has one and the
     * product NAME when it does not — and a name can contain a slash, which a
     * path segment cannot carry. Same fallback the screen itself uses
     * (`r.product_code || r.name` in groupCatalog).
     */
    private function designKey(Request $request, ?string $design): string
    {
        $key = trim((string) ($design ?? $request->query('key', $request->query('code', ''))));

        if ($key === '') {
            $this->fail(400, 'Name the design: ?key=<product code or product name>.');
        }

        return $key;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsForDesign(string $key): array
    {
        $rows = array_values(array_filter(
            CatalogPresenter::all(),
            fn ($row) => ((string) $row['product_code']) !== ''
                ? (string) $row['product_code'] === $key
                : (string) $row['name'] === $key,
        ));

        if ($rows === []) {
            $this->fail(404, 'Design not found: '.$key);
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function unsupportedContentFields(): array
    {
        return array_values(array_diff(
            PendingProductEdits::CONTENT_FIELDS,
            PendingProductEdits::SUPPORTED_CONTENT_FIELDS,
        ));
    }
}
