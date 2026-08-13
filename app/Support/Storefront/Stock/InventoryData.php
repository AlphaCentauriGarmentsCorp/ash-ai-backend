<?php

namespace App\Support\Storefront\Stock;

use App\Models\Storefront\Stock\InventoryLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The remap layer. Everything the Stock module knows about "an inventory row"
 * goes through here.
 *
 * THE ONE DESIGN DECISION WORTH READING. The standalone Stock manager kept its
 * own `inventory` table and pushed changes to a separate website database. Here
 * there is a single database and the shop's own tables already model stock
 * better than the ERP's did, so there is NO stock_inventory table. An ERP
 * "inventory row" is a JOIN of one products row with one product_variants row,
 * and this class is the only place that knows it:
 *
 *   ERP field      reefer_db                       owner
 *   ------------------------------------------------------------------
 *   sku            product_variants.sku            variant
 *   size           product_variants.size           variant
 *   available      product_variants.on_hand        variant   (ERP's "On Hand")
 *   order_allocated product_variants.allocated     variant   (was derived; now stored)
 *   cancelled      product_variants.cancelled_qty  variant
 *   location       product_variants.shelf_location variant
 *   warehouse      product_variants.warehouse      variant
 *   area           product_variants.area           variant
 *   active         product_variants.is_active      variant
 *   weight_g       product_variants.weight_grams   variant
 *   dimensions     width_cm + length_cm            variant   (parsed/formatted here)
 *   name           products.name                   PRODUCT
 *   price          products.price                  PRODUCT   (int pesos, not decimal)
 *   product_code   products.product_code           PRODUCT
 *   image          products.image_path             PRODUCT   (a path, not a filename)
 *   marketplace    products.marketplace            PRODUCT
 *   category       products.type                   PRODUCT
 *   website.*      products.blurb/material/...     PRODUCT
 *
 * The right-hand column is the thing to keep in mind: SIX of the fields the ERP
 * treated as per-SKU are per-DESIGN here. Editing the price of REEFER-OG-WAVE-M
 * changes the price of every size of OG Wave, because in reefer_db there is only
 * one price and it lives on the product. That is a real behaviour change from
 * the standalone app and it is not something this layer can paper over.
 *
 * PRESENTED SPACE. presentProduct() renders a join row into the exact key set
 * Inventory.jsx and Catalog.jsx read, and normaliseChanges() pushes an incoming
 * request body back into that same space before anything is compared or logged.
 * Diffing in one canonical space is what stops '5.00*5.00*5.00' and
 * '5.00*5.00' — the same box, written two ways — from logging a change and
 * dirtying a live row every time somebody saves the panel.
 */
class InventoryData
{
    /**
     * Fields a client may write onto a row. Anything else in a request body
     * (reason, notes, user) is audit metadata and must never reach a table.
     * Same list, same order, as the reference's EDITABLE_FIELDS.
     */
    public const EDITABLE_FIELDS = [
        'name', 'category', 'price', 'location', 'active', 'available', 'marketplace',
        'size', 'image', 'product_code', 'weight_g', 'dimensions', 'warehouse', 'area',
    ];

    /** Of those, the ones that land on `products` — i.e. on the whole design. */
    public const PRODUCT_FIELDS = ['name', 'category', 'price', 'marketplace', 'image', 'product_code'];

    /** And the ones that land on `product_variants` — i.e. on this size only. */
    public const VARIANT_FIELDS = ['location', 'active', 'available', 'size', 'weight_g', 'dimensions', 'warehouse', 'area'];

    /**
     * Standard values for the warehousing fields. Starting points the grid lets
     * anyone overwrite, not constants — and they match the GRID_DEFAULTS the
     * frontend falls back to, so the two never disagree about what a blank box
     * means.
     *
     * `dimensions` is TWO components here, not the ERP's three. reefer_db models
     * a garment as width x length (the flat measurements a size table shows) and
     * has no height column, so a W*L*H string is parsed for its first two parts
     * and rendered back as W*L. The frontend still sends '5.00*5.00*5.00' when a
     * box is cleared; parseDimensions() reads it fine and the diff sees no
     * change, so that costs nothing.
     */
    public const FIELD_DEFAULTS = [
        'weight_g' => 150,
        'dimensions' => '5.00*5.00',
        'warehouse' => 'Reefer QC',
        'area' => 'Storage 1',
    ];

    public const VALID_REASONS = ['New stock received', 'Stock correction', 'Other'];

    public const VALID_MARKETPLACES = ['TikTok', 'REEFER (Website)'];

    /**
     * The ERP's free-text `category` is reefer_db's `products.type`, which is
     * NOT free text — the storefront filters and the /shop routes are built on
     * it. So a category write is validated against this vocabulary instead of
     * being stored as typed.
     *
     * The union of both apps' lists: 'underwear' is the shop's, 'pants' is the
     * one the Catalog's Website View offers (WV_TYPES in Catalog.jsx). Accepting
     * both means neither app can enter a value the other rejects.
     */
    public const VALID_TYPES = ['tee', 'hoodie', 'shorts', 'pants', 'underwear', 'bag', 'socks'];

    public const VALID_AUDIENCES = ['men', 'women', 'unisex', 'accessories'];

    /** Free-text category -> products.type. Everything else is refused. */
    private const TYPE_ALIASES = [
        'tee' => 'tee', 'tees' => 'tee', 't-shirt' => 'tee', 'tshirt' => 'tee',
        't shirt' => 'tee', 'shirt' => 'tee', 'shirts' => 'tee',
        'hoodie' => 'hoodie', 'hoodies' => 'hoodie', 'hood' => 'hoodie', 'sweater' => 'hoodie',
        'shorts' => 'shorts', 'short' => 'shorts',
        'pants' => 'pants', 'pant' => 'pants', 'trousers' => 'pants', 'joggers' => 'pants',
        'underwear' => 'underwear', 'briefs' => 'underwear', 'boxers' => 'underwear',
        'bag' => 'bag', 'bags' => 'bag', 'tote' => 'bag', 'totes' => 'bag',
        'socks' => 'socks', 'sock' => 'socks',
    ];

    /**
     * Column widths, so a value too long for reefer_db is clamped BEFORE it is
     * compared and logged rather than after. Under MySQL strict mode an
     * over-long write throws, and a write that throws inside the queue apply
     * strands the row as "failed" forever.
     */
    private const MAX_LENGTHS = [
        'name' => 255, 'size' => 255, 'location' => 24, 'warehouse' => 60,
        'area' => 60, 'product_code' => 24, 'marketplace' => 60, 'image' => 200,
    ];

    /** Where product photos live on the `public` disk (public/storage/products/). */
    public const IMAGE_DIR = 'products';

    // ------------------------------------------------------------- reading

    /**
     * One ERP inventory row = one variant joined to its product.
     *
     * `is_active` exists on both tables and they mean different things, so both
     * are aliased: variant_active retires ONE size (what the ERP's Status column
     * does), product_active pulls the whole design off the storefront.
     */
    public static function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('storefront_product_variants as v')
            ->join('storefront_products as p', 'p.id', '=', 'v.product_id')
            ->select([
                'v.id as id', 'v.product_id', 'v.sku', 'v.size', 'v.on_hand', 'v.allocated',
                'v.cancelled_qty', 'v.weight_grams', 'v.width_cm', 'v.length_cm',
                'v.shelf_location', 'v.warehouse', 'v.area',
                'v.is_active as variant_active', 'v.created_at', 'v.updated_at',
                'p.slug', 'p.name', 'p.audience', 'p.type', 'p.price', 'p.tag',
                'p.blurb', 'p.material', 'p.fit_name', 'p.fit_desc', 'p.image_path',
                'p.is_active as product_active', 'p.product_code', 'p.marketplace',
            ]);
    }

    public static function findBySku(string $sku): ?object
    {
        return self::baseQuery()->where('v.sku', $sku)->first();
    }

    /**
     * Cast a join row into the shape the two frontend pages read.
     *
     * Keys the UI depends on keep the ERP's names and meanings exactly. Keys the
     * ERP never had (on_hand, allocated, width_cm, product_id, image_url, ...)
     * are ADDED alongside, never in place of one — the shop's schema is richer
     * and hiding that would just mean re-deriving it somewhere else.
     */
    public static function presentProduct(object|array $row): array
    {
        $r = (object) (array) $row;

        $weight = (float) ($r->weight_grams ?? 0);
        $dimensions = self::formatDimensions($r->width_cm ?? null, $r->length_cm ?? null);
        $warehouse = trim((string) ($r->warehouse ?? ''));
        $area = trim((string) ($r->area ?? ''));
        $imagePath = trim((string) ($r->image_path ?? ''));

        return [
            // --- the ERP's own column set, name for name -------------------
            'id' => (int) $r->id,
            'sku' => (string) $r->sku,
            'name' => (string) ($r->name ?? ''),
            // Uppercased, matching this backend's existing ERP feed
            // (Api\InventoryController::row) so the two never produce two
            // spellings of one category in the grid's filter dropdown. It round
            // trips: normaliseType() lowercases before it looks anything up.
            'category' => self::presentCategory($r->type ?? null),
            'size' => (string) ($r->size ?? ''),
            // The ERP's `available` IS the warehouse count — its grid column is
            // headed "On Hand". Sellable stock (on_hand - allocated) is the
            // storefront's business and is deliberately not what this means.
            'available' => (int) ($r->on_hand ?? 0),
            'price' => (float) ($r->price ?? 0),
            'active' => (bool) ($r->variant_active ?? false),
            'location' => (string) ($r->shelf_location ?? ''),
            // Backfilled for display like warehouse/area below, and for the same
            // reason: the grid should never show an empty box where a value is
            // expected. Nothing is written — saving the shown value back diffs as
            // "no change", so an unassigned row stays unassigned in the table.
            'marketplace' => $r->marketplace !== null && $r->marketplace !== ''
                ? (string) $r->marketplace
                : (string) config('reefer.inventory.default_marketplace'),
            // A bare filename, because that is what the frontend appends to its
            // media base and what POST /inventory/photo hands back. The stored
            // value is a PATH; both are exposed, see image_path / image_url.
            'image' => $imagePath !== '' ? basename($imagePath) : '',
            'product_code' => self::productCode($r),
            'weight_g' => $weight > 0 ? $weight : (float) self::FIELD_DEFAULTS['weight_g'],
            'dimensions' => $dimensions !== '' ? $dimensions : self::FIELD_DEFAULTS['dimensions'],
            'warehouse' => $warehouse !== '' ? $warehouse : self::FIELD_DEFAULTS['warehouse'],
            'area' => $area !== '' ? $area : self::FIELD_DEFAULTS['area'],

            // Derived in the ERP by scanning orders; stored on the variant here.
            'order_allocated' => (int) ($r->allocated ?? 0),
            'cancelled' => (int) ($r->cancelled_qty ?? 0),

            'created_at' => $r->created_at ?? null,
            'updated_at' => $r->updated_at ?? null,

            // --- added, because reefer_db knows more than the ERP did -------
            'product_id' => (int) ($r->product_id ?? 0),
            'slug' => (string) ($r->slug ?? ''),
            'audience' => (string) ($r->audience ?? ''),
            'on_hand' => (int) ($r->on_hand ?? 0),
            'allocated' => (int) ($r->allocated ?? 0),
            'cancelled_qty' => (int) ($r->cancelled_qty ?? 0),
            // Sellable right now. The storefront's own rule: an inactive size
            // sells nothing, and an over-allocated one floors at zero rather
            // than going negative.
            'sellable' => (bool) ($r->variant_active ?? false)
                ? max(0, (int) ($r->on_hand ?? 0) - (int) ($r->allocated ?? 0)) : 0,
            'width_cm' => $r->width_cm !== null ? (float) $r->width_cm : null,
            'length_cm' => $r->length_cm !== null ? (float) $r->length_cm : null,
            'image_path' => $imagePath !== '' ? $imagePath : null,
            // asset(), not Storage::disk('public')->url(): the disk driver builds
            // from config APP_URL, which on this box is still http://localhost, so
            // every ERP thumbnail pointed at the developer's own machine. asset()
            // derives from the incoming request, which is what the shop's own
            // ProductResource already does — one photo, one URL, both screens.
            //
            // Behind a TLS-terminating proxy this needs TrustProxies configured, or
            // the request looks like http:// and an https:// page blocks the image
            // as mixed content.
            'image_url' => $imagePath !== '' ? asset('storage/'.$imagePath) : null,
            // The whole-design kill switch, which is NOT what `active` means.
            'product_active' => (bool) ($r->product_active ?? false),
        ];
    }

    /**
     * Canonical row ordering: designs in product_code order (R001, R002, ...),
     * and within a design its sizes in wearable order. SQL cannot do the size
     * half, so it is sorted here — same as the reference.
     */
    public static function compareRows(array $a, array $b): int
    {
        $aCode = (string) ($a['product_code'] ?? '');
        $bCode = (string) ($b['product_code'] ?? '');
        $aMissing = $aCode === '' ? 1 : 0;
        $bMissing = $bCode === '' ? 1 : 0;
        if ($aMissing !== $bMissing) {
            return $aMissing - $bMissing;
        }
        if ($aCode !== $bCode) {
            return strcmp($aCode, $bCode);
        }

        $aRank = SizeOrder::sizeRank($a['size'] ?? '');
        $bRank = SizeOrder::sizeRank($b['size'] ?? '');
        if ($aRank !== $bRank) {
            return $aRank - $bRank;
        }

        return strcmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? ''));
    }

    /** The full presented list in canonical order — GET /inventory and the export. */
    public static function presentedList(): array
    {
        $rows = self::baseQuery()->get()->map(fn ($r) => self::presentProduct($r))->all();
        usort($rows, [self::class, 'compareRows']);

        return $rows;
    }

    // ------------------------------------------------- website content

    /**
     * The storefront copy the Catalog's Website View edits, per DESIGN.
     *
     * In the ERP this was a separate `product_content` table keyed by
     * product_code. Here it is columns on `products`, which is where the
     * storefront already reads it from — so a push is live on the site
     * immediately rather than after a sync.
     *
     * SIX OF THE FOURTEEN FIELDS HAVE NO COLUMN in reefer_db: color,
     * print_method, care, origin, image_back and image_detail. They are reported
     * as null rather than omitted (the UI reads the key), and queueing one is
     * refused with a clear message instead of being accepted and silently
     * dropped at push time. See needsWiring — supporting them means adding
     * columns to `products`, which is a shop table this module may not alter.
     */
    public const CONTENT_COLUMNS = [
        'tag' => 'tag',
        'audience' => 'audience',
        'type' => 'type',
        'blurb' => 'blurb',
        'material' => 'material',
        'fit_name' => 'fit_name',
        'fit_desc' => 'fit_desc',
        'image_front' => 'image_path',
    ];

    /**
     * Attach each design's website content to every presented row.
     *
     * GET /inventory only: the Excel export keeps its exact column set, and the
     * Catalog's Website View reads these off the same feed it already polls.
     *
     * Unlike the reference this is never null — every variant has a product, and
     * a product always has at least a type and an audience. Fields nobody has
     * written are null inside the object, which is the state the UI renders as
     * "not set (site keeps its own)".
     */
    public static function attachWebsiteContent(array $rows): array
    {
        $products = DB::table('storefront_products')
            ->select(array_merge(['id'], array_values(array_unique(self::CONTENT_COLUMNS))))
            ->get()->keyBy('id');

        return array_map(function ($row) use ($products) {
            $product = $products[$row['product_id'] ?? 0] ?? null;
            $row['website'] = $product === null ? null : self::websiteFor($product);

            return $row;
        }, $rows);
    }

    /** One design's website-content object, all 14 keys, unset ones null. */
    public static function websiteFor(object $product): array
    {
        $website = [];
        foreach (PendingProductEdits::CONTENT_FIELDS as $field) {
            $column = self::CONTENT_COLUMNS[$field] ?? null;
            if ($column === null) {
                // No column in reefer_db — see the note on CONTENT_COLUMNS.
                $website[$field] = null;

                continue;
            }

            $value = trim((string) ($product->$column ?? ''));
            if ($field === 'image_front' && $value !== '') {
                // Stored as a path, consumed by the UI as a bare filename.
                $value = basename($value);
            }
            $website[$field] = $value !== '' ? $value : null;
        }

        return $website;
    }

    // ------------------------------------------------------------- logging

    /**
     * Append one row to the Activity Log.
     *
     * Values are clamped to their column widths first. The reference did not,
     * because its own columns were wider than anything it wrote; here a long
     * free-text reason or a notes string that has had the ' · Push Product — …'
     * marker appended would throw under strict mode, and a throw inside
     * applyOne() leaves the queue row stuck as "failed" on every subsequent
     * push. Losing the tail of a note is better than losing the audit row.
     */
    public static function logMovement(array $entry): void
    {
        $clamp = fn (?string $value, int $max) => $value === null ? null : mb_substr($value, 0, $max);

        InventoryLog::create([
            'sku' => $clamp((string) $entry['sku'], 255),
            'product_name' => $clamp(
                isset($entry['product_name']) ? (string) $entry['product_name'] : null, 255
            ),
            'field' => $clamp((string) $entry['field'], 32),
            // TEXT columns — no clamp needed, and content pushes are paragraphs.
            'old_value' => (string) ($entry['old_value'] ?? ''),
            'new_value' => (string) ($entry['new_value'] ?? ''),
            'delta' => $clamp((string) ($entry['delta'] ?? ''), 50),
            'reason' => $clamp((string) $entry['reason'], 100),
            'notes' => $clamp((string) ($entry['notes'] ?? ''), 600),
            'user' => $clamp(((string) ($entry['user'] ?? '')) !== '' ? (string) $entry['user'] : 'unknown', 100),
        ]);
    }

    // ------------------------------------------------------------- writing

    /**
     * Push a raw request body into presented space: whitelist it, coerce each
     * value to its canonical form, clamp it to its column width, and drop
     * anything that cannot be stored.
     *
     * Doing this BEFORE the diff is what makes "no changes to save" mean it.
     *
     * @param  bool  $strict  true from PUT/POST, where an unusable value should be
     *                        reported to the person who typed it; false from the bulk
     *                        Excel import, where the reference silently skips bad cells
     *                        rather than failing 200 good rows over one of them.
     *
     * @throws \InvalidArgumentException when $strict and a value is unusable
     */
    public static function normaliseChanges(array $raw, bool $strict = true): array
    {
        $changes = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (! array_key_exists($field, $raw)) {
                continue;
            }
            $value = $raw[$field];

            switch ($field) {
                case 'name':
                    $name = trim((string) $value);
                    if ($name === '') {
                        if ($strict) {
                            throw new \InvalidArgumentException("Product name can't be empty.");
                        }

                        break;
                    }
                    $changes['name'] = mb_substr($name, 0, self::MAX_LENGTHS['name']);
                    break;

                case 'category':
                    $type = self::normaliseType((string) $value);
                    if ($type === null) {
                        if ($strict) {
                            throw new \InvalidArgumentException(
                                'Category must be one of: '.implode(', ', self::VALID_TYPES)
                            );
                        }

                        break;
                    }
                    // Stored back in PRESENTED form ('TEE'), not the column's
                    // form ('tee'). Everything here is presented space, and
                    // mixing the two would make differingFields() see a change
                    // on every single save — one phantom "Category updated"
                    // audit row, and one needless write to a live products row,
                    // every time anybody pressed Save. applyChanges() converts
                    // back on the way to the column.
                    $changes['category'] = self::presentCategory($type);
                    break;

                case 'price':
                    if (! is_numeric($value)) {
                        if ($strict) {
                            throw new \InvalidArgumentException('Enter a valid price (0 or more).');
                        }

                        break;
                    }
                    // products.price is whole pesos, unsigned. Rounding here
                    // rather than at the INSERT keeps the log honest about what
                    // was actually stored.
                    $changes['price'] = (float) max(0, (int) round((float) $value));
                    break;

                case 'available':
                    if (! is_numeric($value)) {
                        if ($strict) {
                            throw new \InvalidArgumentException('Enter a valid quantity (0 or more).');
                        }

                        break;
                    }
                    $changes['available'] = max(0, (int) $value);
                    break;

                case 'active':
                    $changes['active'] = (bool) filter_var($value, FILTER_VALIDATE_BOOL);
                    break;

                case 'marketplace':
                    $marketplace = trim((string) $value);
                    // '' is "not specified", never "clear it". The Import Stock
                    // form posts the whole row including an empty marketplace on
                    // every update; treating that as a change would 400 the save
                    // of a product nobody has assigned a marketplace to yet.
                    if ($marketplace === '') {
                        break;
                    }
                    if (! in_array($marketplace, self::VALID_MARKETPLACES, true)) {
                        if ($strict) {
                            throw new \InvalidArgumentException(
                                'Marketplace must be one of: '.implode(', ', self::VALID_MARKETPLACES)
                            );
                        }

                        break;
                    }
                    $changes['marketplace'] = $marketplace;
                    break;

                case 'size':
                    $changes['size'] = mb_substr(trim((string) $value), 0, self::MAX_LENGTHS['size']);
                    break;

                case 'image':
                    // Only ever a bare filename from POST /inventory/photo. A
                    // path component would let a queued value point outside the
                    // photo directory, so it is stripped rather than rejected.
                    $changes['image'] = mb_substr(basename(trim((string) $value)), 0, self::MAX_LENGTHS['image']);
                    break;

                case 'product_code':
                    $changes['product_code'] = mb_substr(trim((string) $value), 0, self::MAX_LENGTHS['product_code']);
                    break;

                case 'location':
                    $changes['location'] = mb_substr(trim((string) $value), 0, self::MAX_LENGTHS['location']);
                    break;

                case 'weight_g':
                    // Blank or zero means "reset to standard", not "store 0".
                    $weight = is_numeric($value) ? (float) $value : 0.0;
                    $changes['weight_g'] = $weight > 0
                        ? min($weight, 999999.99)
                        : (float) self::FIELD_DEFAULTS['weight_g'];
                    break;

                case 'dimensions':
                    $parsed = self::parseDimensions((string) $value);
                    if ($parsed === null) {
                        // Blank resets to standard, same as the reference.
                        if (trim((string) $value) === '') {
                            $changes['dimensions'] = self::FIELD_DEFAULTS['dimensions'];

                            break;
                        }
                        if ($strict) {
                            throw new \InvalidArgumentException(
                                'Size (cm) must look like 5.00*5.00 (width*length).'
                            );
                        }

                        break;
                    }
                    $changes['dimensions'] = self::formatDimensions($parsed[0], $parsed[1]);
                    break;

                case 'warehouse':
                case 'area':
                    $text = trim((string) $value);
                    $changes[$field] = mb_substr(
                        $text !== '' ? $text : (string) self::FIELD_DEFAULTS[$field],
                        0,
                        self::MAX_LENGTHS[$field]
                    );
                    break;
            }
        }

        return $changes;
    }

    /**
     * Which normalised changes actually differ from what is on file.
     *
     * Both sides are already in presented space, so this is a straight compare —
     * numerics loosely, everything else as strings.
     */
    public static function differingFields(array $changes, array $current): array
    {
        return array_values(array_filter(array_keys($changes), function ($field) use ($changes, $current) {
            if ($field === 'active') {
                return (bool) $changes['active'] !== (bool) ($current['active'] ?? false);
            }
            if (in_array($field, ['price', 'available', 'weight_g'], true)) {
                return (float) $changes[$field] !== (float) ($current[$field] ?? 0);
            }

            return (string) $changes[$field] !== (string) ($current[$field] ?? '');
        }));
    }

    /**
     * Write a normalised change set onto the two tables.
     *
     * ONLY the fields that changed are written. The reference rewrote every
     * product column on every save, which was harmless against its own private
     * table and is not harmless here: `products` and `product_variants` are the
     * live shop's, other code writes them (checkout raises `allocated`), and a
     * blind full-row UPDATE would clobber a concurrent write and would also
     * MATERIALISE the display defaults — stamping 150 g and 'Reefer QC' onto 61
     * variants that never had them.
     *
     * The one rule carried over verbatim: a variant whose on_hand reaches zero
     * goes inactive. It is what the On Hand column's own help text promises.
     *
     * @param  object  $row  a join row from baseQuery(), already locked by the caller
     */
    public static function applyChanges(object $row, array $changes): void
    {
        $productUpdate = [];
        $variantUpdate = [];

        foreach ($changes as $field => $value) {
            switch ($field) {
                case 'name':          $productUpdate['name'] = $value;
                    break;
                // Presented space is uppercase ('TEE'); the column holds the
                // storefront's own lowercase vocabulary ('tee').
                case 'category':
                    $type = self::normaliseType((string) $value);
                    if ($type !== null) {
                        $productUpdate['type'] = $type;
                    }
                    break;
                case 'price':         $productUpdate['price'] = max(0, (int) round((float) $value));
                    break;
                case 'marketplace':   $productUpdate['marketplace'] = $value;
                    break;
                case 'product_code':  $productUpdate['product_code'] = $value !== '' ? $value : null;
                    break;
                case 'image':         $productUpdate['image_path'] = self::imagePath($value);
                    break;

                case 'size':          $variantUpdate['size'] = $value;
                    break;
                case 'available':     $variantUpdate['on_hand'] = max(0, (int) $value);
                    break;
                case 'active':        $variantUpdate['is_active'] = $value ? 1 : 0;
                    break;
                case 'location':      $variantUpdate['shelf_location'] = $value !== '' ? $value : null;
                    break;
                case 'weight_g':      $variantUpdate['weight_grams'] = (float) $value;
                    break;
                case 'warehouse':     $variantUpdate['warehouse'] = $value;
                    break;
                case 'area':          $variantUpdate['area'] = $value;
                    break;
                case 'dimensions':
                    $parsed = self::parseDimensions((string) $value);
                    if ($parsed !== null) {
                        $variantUpdate['width_cm'] = $parsed[0];
                        $variantUpdate['length_cm'] = $parsed[1];
                    }
                    break;
            }
        }

        // Zero on hand can never be Active.
        //
        // NARROWED from the reference, deliberately. It re-applied this on EVERY
        // save, reading the merged row — so against reefer_db, editing a shelf
        // code on any of the sold-out-but-still-listed variants would quietly
        // pull that size off the storefront, an effect nobody asked for and
        // nothing logs. It fires here only when this request is the thing that
        // set stock to zero, or is trying to activate a SKU that has none, which
        // is exactly what the On Hand column's help text promises.
        $settingStockToZero = array_key_exists('on_hand', $variantUpdate) && $variantUpdate['on_hand'] === 0;
        $activatingWithNoStock = ($variantUpdate['is_active'] ?? 0) === 1
            && (array_key_exists('on_hand', $variantUpdate) ? $variantUpdate['on_hand'] : (int) $row->on_hand) === 0;

        if ($settingStockToZero || $activatingWithNoStock) {
            $variantUpdate['is_active'] = 0;
        }

        $now = now();

        if ($productUpdate !== []) {
            $productUpdate['updated_at'] = $now;
            DB::table('storefront_products')->where('id', $row->product_id)->update($productUpdate);
        }
        if ($variantUpdate !== []) {
            $variantUpdate['updated_at'] = $now;
            DB::table('storefront_product_variants')->where('id', $row->id)->update($variantUpdate);

            // Keep the DESIGN in step whenever this save changed whether a size is
            // live. There are two ways to flip a variant — this direct edit
            // (PUT /inventory/{sku}) and the queued Push Product path — and the
            // storefront gates on products.is_active as well, so BOTH have to
            // cascade or activating a size from the Inventory grid leaves the
            // product page invisible while the grid reports it Active.
            if (array_key_exists('is_active', $variantUpdate)) {
                self::syncProductActive((int) $row->product_id);
            }
        }
    }

    /**
     * Create a brand-new SKU and log its opening quantity.
     *
     * Where the reference inserted one `inventory` row, this has to decide
     * whether it is adding a SIZE to a design that already exists or creating a
     * whole new design. The design is matched on product_code first — that is
     * what the field is for, and the Add Product form prefills from it — then on
     * an exact name match, which is the same grouping rule the Inventory grid
     * itself uses client-side (`r.product_code || r.name`). Only when neither
     * finds anything is a new `products` row created.
     *
     * PUBLISHING. The rule this file used to state — "new rows ALWAYS land
     * inactive ... then somebody deliberately activates from the Catalog" — was
     * only half true, because nothing in the codebase could activate a PRODUCT.
     * The Catalog's status pill flips product_variants.is_active; products.is_active
     * was written 0 here and never written again by anything, so every design
     * created through the stock manager was permanently invisible to the shop.
     *
     * The guard behind that rule is still worth keeping, and it is kept: the
     * concern was "an empty product page goes live the moment a warehouse hand
     * types a SKU". So completeness decides, not a blanket 0 — a design goes live
     * when it is actually shoppable (see stock.publish.auto), and a half-typed one
     * still lands inactive and waits for the Catalog pill, which now works.
     *
     * @see self::syncProductActive() for the variant -> design cascade.
     *
     * Caller guarantees sku/name are non-empty and wraps this in a transaction.
     *
     * @throws \RuntimeException when the design already stocks that size
     */
    public static function insertProduct(string $sku, string $name, array $fields, string $user, ?string $notes = null): void
    {
        $changes = self::normaliseChanges($fields, false);

        $code = (string) ($changes['product_code'] ?? '');
        $size = (string) ($changes['size'] ?? '');
        $available = (int) ($changes['available'] ?? 0);

        // findDesignByCode, not a bare product_code lookup: the Add Product form
        // offers whatever product_code the grid showed, which for a design that
        // has none is the synthetic 'P0007'. Picking it must attach a size to
        // that design, not invent a second copy of it named the same thing.
        $product = $code !== '' ? self::findDesignByCode($code) : null;
        if ($product === null) {
            $product = DB::table('storefront_products')->where('name', $name)->first();
        }
        // A synthetic code identifies a design but is not a code to store.
        $storedCode = ($code !== '' && ! preg_match('/^P\d{4,}$/', $code)) ? $code : null;

        $now = now();

        if ($product === null) {
            // normaliseChanges left this in presented form ('TEE'); the column
            // takes the storefront's lowercase vocabulary. Defaulting to 'tee'
            // is the least surprising choice for a garment shop and the value a
            // new design is most often corrected to anyway.
            $type = self::normaliseType((string) ($changes['category'] ?? '')) ?? 'tee';
            $price = max(0, (int) round((float) ($changes['price'] ?? 0)));
            $productId = DB::table('storefront_products')->insertGetId([
                'slug' => self::uniqueSlug($name),
                'name' => $name,
                'audience' => self::audienceFor($fields, $type),
                'type' => $type,
                'price' => $price,
                'image_path' => self::imagePath((string) ($changes['image'] ?? '')),
                // Blurb is the one storefront-facing field the Add Product form has
                // no input for, and the product page interpolates it unguarded — a
                // null here reaches the customer as the literal word "null". Seed a
                // neutral line; staff replace it from the Catalog's Website View.
                'blurb' => self::openingBlurb($name),
                'is_active' => self::shouldPublish($price, $available) ? 1 : 0,
                'sort' => 0,
                'product_code' => $storedCode,
                'marketplace' => $changes['marketplace'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $product = (object) ['id' => $productId, 'name' => $name];
        } else {
            // product_variants has unique(product_id, size); hitting it would
            // abort the whole surrounding transaction, so it is checked instead.
            $clash = DB::table('storefront_product_variants')
                ->where('product_id', $product->id)->where('size', $size)->first();
            if ($clash !== null) {
                throw new \RuntimeException(
                    $name.' already stocks size "'.($size !== '' ? $size : '—').'" as SKU '.$clash->sku.'.'
                );
            }
        }

        $dimensions = self::parseDimensions((string) ($changes['dimensions'] ?? self::FIELD_DEFAULTS['dimensions']))
            ?? self::parseDimensions(self::FIELD_DEFAULTS['dimensions']);

        DB::table('storefront_product_variants')->insert([
            'product_id' => $product->id,
            'size' => $size,
            'sku' => $sku,
            'on_hand' => $available,
            // Nothing can be reserved against a SKU that did not exist a moment
            // ago; both counters start clean.
            'allocated' => 0,
            'cancelled_qty' => 0,
            'weight_grams' => (float) ($changes['weight_g'] ?? self::FIELD_DEFAULTS['weight_g']),
            'width_cm' => $dimensions[0],
            'length_cm' => $dimensions[1],
            'shelf_location' => ($changes['location'] ?? '') !== '' ? $changes['location'] : null,
            'warehouse' => (string) ($changes['warehouse'] ?? self::FIELD_DEFAULTS['warehouse']),
            'area' => (string) ($changes['area'] ?? self::FIELD_DEFAULTS['area']),
            // Zero on hand can never be Active — the same invariant every other
            // write path in this codebase enforces (see PendingProductEdits'
            // 'active' and 'available' branches). A size opened at 0 stays dark
            // until a restock, which is also what the shop's picker expects.
            'is_active' => $available > 0 ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Adding a size to a design that already exists can bring a dark design
        // back to life, so the cascade runs on BOTH branches, not just on create.
        $live = self::syncProductActive((int) $product->id);

        self::logMovement([
            'sku' => $sku,
            'product_name' => $name,
            'field' => 'available',
            'old_value' => 0,
            'new_value' => $available,
            'delta' => $available,
            'reason' => 'New stock received',
            'notes' => ($notes ?? 'Product added manually')
                .($live
                    ? ' · live on the shop'
                    : ' · created Inactive — set a price and stock, then activate from the Catalog'),
            'user' => $user,
        ]);
    }

    /**
     * Point a design's is_active at the truth of its sizes: a design is live when
     * at least one of its sizes is live, and dark when none are.
     *
     * The storefront gates on BOTH flags — ProductController filters products by
     * is_active, and ProductVariant::available reports 0 for an inactive variant —
     * so the two have to be kept in step or the shop shows a product page whose
     * every size is sold out, or hides sizes that are genuinely in stock.
     *
     * Deriving this rather than storing a separate publish flag is deliberate:
     * there is then no third state to drift. Returns whether the design is live.
     */
    public static function syncProductActive(int $productId): bool
    {
        $live = DB::table('storefront_product_variants')
            ->where('product_id', $productId)
            ->where('is_active', 1)
            ->exists();

        DB::table('storefront_products')
            ->where('id', $productId)
            ->where('is_active', '!=', $live ? 1 : 0)
            ->update(['is_active' => $live ? 1 : 0, 'updated_at' => now()]);

        return $live;
    }

    /**
     * Whether a freshly created design is complete enough to face customers.
     *
     * This is the guard the old blanket `is_active = 0` was standing in for —
     * "an empty product page goes live the moment a warehouse hand types a SKU".
     * A design with a price and something to sell is not that page.
     *
     * Set stock.publish.auto = false to restore approve-before-live, in which case
     * everything lands dark and the Catalog's status pill publishes it.
     */
    public static function shouldPublish(int $price, int $available): bool
    {
        if (! config('stock.publish.auto', true)) {
            return false;
        }

        return $price >= (int) config('stock.publish.min_price', 1) && $available > 0;
    }

    /**
     * A neutral opening line for a design that has no copy yet.
     *
     * products.blurb is NOT NULL-safe downstream: the storefront product page
     * interpolates it directly, so a null arrives on screen as the four letters
     * "null". The Add Product form has no blurb input, so one is seeded here and
     * rewritten from the Catalog's Website View when marketing gets to it.
     */
    public static function openingBlurb(string $name): string
    {
        return trim($name).' — new in. Full details coming soon.';
    }

    // ------------------------------------------------------------- helpers

    /**
     * The ERP's Category column, from products.type.
     *
     * Uppercased to match this backend's existing ERP feed
     * (Api\InventoryController::row) — with two spellings in circulation the
     * grid's category filter would list "tee" and "TEE" as separate options and
     * each would hide half the rows. It round-trips safely because
     * normaliseType() lowercases before it looks anything up.
     */
    public static function presentCategory(?string $type): string
    {
        return strtoupper(str_replace('-', ' ', (string) $type));
    }

    /**
     * The design key the Catalog screen groups on and the content queue keys by.
     *
     * products.product_code is nullable and arrived without a backfill, so most
     * of the 18 live designs have none. A blank code is not a harmless gap: the
     * Website View passes this value straight back as the `sku` of a content
     * edit, so a design without one cannot have its storefront copy edited at
     * all. Rows with no code therefore get a synthetic one derived from the
     * product id — 'P0007'.
     *
     * It is DERIVED, never written. The id is already unique and stable, so the
     * code is too; findDesignByCode() reads the same form back; and the moment a
     * real product_code is set on the row, it wins. Deriving rather than
     * backfilling also keeps this module off `products`, which it may not alter.
     */
    public static function productCode(object $row): string
    {
        $code = trim((string) ($row->product_code ?? ''));
        if ($code !== '') {
            return $code;
        }

        $productId = (int) ($row->product_id ?? $row->id ?? 0);

        return $productId > 0 ? 'P'.str_pad((string) $productId, 4, '0', STR_PAD_LEFT) : '';
    }

    /**
     * Resolve a design from whatever product_code the client sent — a real one,
     * or the synthetic 'P0007' form productCode() hands out for a design that
     * has none.
     */
    public static function findDesignByCode(string $code): ?object
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $product = DB::table('storefront_products')->where('product_code', $code)->first();
        if ($product !== null) {
            return $product;
        }

        // Synthetic form. Matched on the id only when the row really has no code
        // of its own, so a design whose actual product_code happens to read
        // 'P0007' can never be shadowed by another design's id.
        if (preg_match('/^P(\d{4,})$/', $code, $m)) {
            return DB::table('storefront_products')
                ->where('id', (int) $m[1])
                ->where(fn ($q) => $q->whereNull('product_code')->orWhere('product_code', ''))
                ->first();
        }

        return null;
    }

    /** Free-text category -> a products.type the storefront understands. */
    public static function normaliseType(string $raw): ?string
    {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', $raw) ?? $raw));
        if ($key === '') {
            return null;
        }

        return self::TYPE_ALIASES[$key] ?? (in_array($key, self::VALID_TYPES, true) ? $key : null);
    }

    /**
     * products.audience is NOT NULL and the ERP never had the concept, so a new
     * design needs one picked for it. Bags and socks are accessories; everything
     * else defaults to unisex, which is the safest thing to show on a shop that
     * lists by audience.
     */
    private static function audienceFor(array $fields, string $type): string
    {
        $given = strtolower(trim((string) ($fields['audience'] ?? '')));
        if (in_array($given, self::VALID_AUDIENCES, true)) {
            return $given;
        }

        return in_array($type, ['bag', 'socks'], true) ? 'accessories' : 'unisex';
    }

    /**
     * "5.00*5.00" or the ERP's three-part "5.00*5.00*5.00" -> [width, length].
     *
     * The third component is a HEIGHT and reefer_db has nowhere to put it: a
     * garment is measured flat, width by length, and that is what the size table
     * on the storefront shows. Accepting the three-part form anyway means the
     * frontend's own default string, every exported workbook and every row typed
     * by someone used to the old app all still parse.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function parseDimensions(string $raw): ?array
    {
        $parts = preg_split('/[*x×,\s]+/iu', trim($raw)) ?: [];
        $numbers = [];
        foreach ($parts as $part) {
            $clean = str_replace(['cm', 'CM'], '', trim($part));
            if ($clean !== '' && is_numeric($clean)) {
                $numbers[] = min(max((float) $clean, 0), 9999.99);   // decimal(6,2)
            }
        }

        return count($numbers) >= 2 ? [$numbers[0], $numbers[1]] : null;
    }

    /** [width, length] -> "5.00*5.00". Both null (never measured) -> ''. */
    public static function formatDimensions(mixed $width, mixed $length): string
    {
        if (($width === null || $width === '') && ($length === null || $length === '')) {
            return '';
        }

        return number_format((float) $width, 2, '.', '').'*'.number_format((float) $length, 2, '.', '');
    }

    /** A bare photo filename -> the path stored in products.image_path. */
    public static function imagePath(string $filename): ?string
    {
        $filename = basename(trim($filename));

        return $filename !== '' ? self::IMAGE_DIR.'/'.$filename : null;
    }

    /** products.slug is unique and NOT NULL; a new design has to be given one. */
    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'product';
        }
        $base = mb_substr($base, 0, 240);

        $slug = $base;
        $n = 2;
        while (DB::table('storefront_products')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }
}
