<?php

namespace App\Http\Controllers\Storefront\Stock;

use App\Http\Controllers\Controller;
use App\Models\Storefront\Stock\InventoryLog;
use App\Support\Storefront\Stock\InventoryData;
use App\Support\Storefront\Stock\InventoryXlsx;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The Inventory API — port of the Stock manager's InventoryController, mounted at
 * /api/stocks/inventory/*.
 *
 * Same endpoints, same JSON shapes, same error bodies ({"error": "..."}), same
 * status codes. Inventory.jsx and Catalog.jsx move over unchanged and read the
 * responses key for key.
 *
 * WHAT IS UNDERNEATH IS COMPLETELY DIFFERENT. The reference owned a private
 * `inventory` table. There is none here: every row is a JOIN of the live shop's
 * `products` and `product_variants`, and the whole remap lives in
 * App\Support\Storefront\Stock\InventoryData. So the sentence worth keeping in mind while
 * reading any write path below is that these endpoints edit the REAL catalogue
 * of a shop with 18 products, 61 SKUs and 15 orders against them. Three
 * consequences run through the file:
 *
 *   1. Name, Category, Price, Product Code, Marketplace and the photo belong to
 *      the DESIGN. Editing them on one SKU changes every size of that product.
 *   2. Only fields that actually changed are written, never the whole row.
 *   3. Deleting is a variant delete, it refuses while stock is reserved against
 *      open orders, and it never removes a `products` row. See destroy().
 */
class InventoryController extends Controller
{
    /**
     * Abort the surrounding DB::transaction AND send the given JSON error.
     *
     * The reference's pattern was rollback-then-return; throwing
     * HttpResponseException gets Laravel to do both (the transaction helper
     * rolls back on any throw, then the exception renders as its response).
     */
    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => $message], $status));
    }

    /** Whoever the client claims to be, for the audit trail. */
    private function actor(Request $request): string
    {
        $claimed = trim((string) $request->input('user', ''));
        if ($claimed !== '') {
            return $claimed;
        }

        // Falls back to the authenticated staff username the module's middleware
        // put on the request, so an audit row is attributable even when the
        // client forgets to send one.
        $auth = $request->attributes->get('authUser');

        return is_array($auth) && ($auth['username'] ?? '') !== '' ? (string) $auth['username'] : 'unknown';
    }

    /**
     * GET /inventory — every SKU, in canonical order, with its design's website
     * content attached as a nested `website` object.
     *
     * Both frontend pages poll this: the Inventory grid for stock, the Catalog
     * for prices, status pills and the Website View.
     */
    public function index()
    {
        return response()->json(
            InventoryData::attachWebsiteContent(InventoryData::presentedList())
        );
    }

    /**
     * GET /inventory/logs — the Activity Log, newest first, optional ?sku=.
     *
     * The human-readable log_id ("LOG-1001") is derived as 'LOG-' + (1000 + id),
     * same as the reference.
     */
    public function logs(Request $request)
    {
        $query = InventoryLog::query()->withLogId();

        if ($request->query('sku')) {
            $query->where('sku', $request->query('sku'));
        }

        $rows = $query->newestFirst()->get()->map(function ($row) {
            $out = $row->toArray();
            $out['id'] = (int) $row->id;
            $out['log_id'] = $row->log_id;

            return $out;
        });

        return response()->json($rows);
    }

    private const XLSX_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** GET /inventory/export — a fresh workbook built from the live tables. */
    public function export()
    {
        $rows = InventoryData::presentedList();

        $logRows = InventoryLog::query()->withLogId()->newestFirst()->get()
            ->map(function ($row) {
                $out = $row->toArray();
                $out['log_id'] = $row->log_id;

                return $out;
            })->all();

        $generatedAt = now('Asia/Manila')->format('M j, Y g:i A');
        $bytes = InventoryXlsx::buildExportWorkbook($rows, $logRows, $generatedAt);

        return response($bytes, 200, [
            'Content-Type' => self::XLSX_CONTENT_TYPE,
            'Content-Disposition' => 'attachment; filename="reefer-inventory-'.now('Asia/Manila')->format('Y-m-d').'.xlsx"',
        ]);
    }

    /** GET /inventory/import-template — the blank, pre-formatted workbook. */
    public function importTemplate()
    {
        return response(InventoryXlsx::buildImportTemplate(), 200, [
            'Content-Type' => self::XLSX_CONTENT_TYPE,
            'Content-Disposition' => 'attachment; filename="reefer-inventory-import-template.xlsx"',
        ]);
    }

    /**
     * Reason recorded in the Activity Log for each field a bulk import changes.
     * Anything not listed falls back to 'Product details updated', so every
     * changed field leaves a record.
     */
    private const IMPORT_LOG_REASONS = [
        'name' => 'Name updated',
        'category' => 'Category updated',
        'size' => 'Size updated',
        'product_code' => 'Product code updated',
        'available' => 'Stock correction',
        'price' => 'Price update',
        'active' => 'Status update',
        'location' => 'Shelf updated',
        'warehouse' => 'Warehouse updated',
        'area' => 'Area updated',
        'weight_g' => 'Weight updated',
        'dimensions' => 'Size (cm) updated',
        'marketplace' => 'Marketplace updated',
    ];

    /**
     * POST /inventory/import-xlsx — bulk update from an uploaded workbook.
     *
     * Rows are matched by SKU and only the cells that were actually filled in
     * are written. Update-only by default; createMissing="1" opts into creating
     * unknown SKUs (those rows still need a Product Name).
     */
    public function importXlsx(Request $request)
    {
        $file = $request->file('file');
        if ($file === null) {
            return response()->json(['error' => 'No file uploaded.'], 400);
        }
        if (! preg_match('/\.xlsx$/i', $file->getClientOriginalName())) {
            return response()->json(['error' => 'Only .xlsx files are supported. Save your sheet as Excel Workbook (.xlsx) and try again.'], 400);
        }
        if ($file->getSize() > 8 * 1024 * 1024) {
            return response()->json(['error' => 'File too large (8 MB limit).'], 400);
        }

        $user = $this->actor($request);
        $createMissing = $request->input('createMissing') === '1';

        $sourceFile = trim((string) $file->getClientOriginalName());
        $importNote = $sourceFile !== '' ? 'Excel import — '.$sourceFile : 'Excel import';

        try {
            $parsed = InventoryXlsx::parseImportWorkbook($file->getRealPath());
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not read that workbook: '.$e->getMessage()], 400);
        }

        if (count($parsed['rows']) === 0) {
            return response()->json(['error' => 'No rows with a SKU were found below the header row.'], 400);
        }

        $notFound = [];
        $missingName = [];
        $unchanged = [];
        $failed = [];
        $created = 0;
        $updated = 0;
        $fieldChanges = 0;
        $skippedExample = 0;

        DB::transaction(function () use ($parsed, $createMissing, $user, $importNote, &$notFound, &$missingName, &$unchanged, &$failed, &$created, &$updated, &$fieldChanges, &$skippedExample) {
            foreach ($parsed['rows'] as $entry) {
                // The template's amber sample row — never real data.
                if ($entry['sku'] === InventoryXlsx::EXAMPLE_SKU) {
                    $skippedExample++;

                    continue;
                }

                $locked = DB::table('storefront_product_variants')->where('sku', $entry['sku'])->lockForUpdate()->first();
                $existing = $locked === null ? null : InventoryData::findBySku($entry['sku']);

                if ($existing === null) {
                    if (! $createMissing) {
                        $notFound[] = $entry['sku'];

                        continue;
                    }
                    $name = trim((string) ($entry['fields']['name'] ?? ''));
                    if ($name === '') {
                        $missingName[] = $entry['sku'];

                        continue;
                    }
                    try {
                        InventoryData::insertProduct($entry['sku'], $name, $entry['fields'], $user, $importNote.' (new product)');
                        $created++;
                    } catch (\RuntimeException $e) {
                        // A size clash on an existing design. Reported rather
                        // than thrown: one impossible row must not roll back the
                        // 200 good ones around it.
                        $failed[] = $entry['sku'].': '.$e->getMessage();
                    }

                    continue;
                }

                // Non-strict: a cell the shop cannot store (an unknown category,
                // a marketplace that is not one of the two) is dropped rather
                // than failing the whole import, exactly as the reference did.
                $changes = InventoryData::normaliseChanges($entry['fields'], false);

                $current = InventoryData::presentProduct($existing);
                $differing = InventoryData::differingFields($changes, $current);

                if (count($differing) === 0) {
                    $unchanged[] = $entry['sku'];

                    continue;
                }

                // Same audit trail a manual edit produces.
                foreach ($differing as $field) {
                    $oldValue = $field === 'active'
                        ? ($current['active'] ? 'Active' : 'Inactive')
                        : ($current[$field] ?? '');
                    $newValue = $field === 'active'
                        ? ($changes['active'] ? 'Active' : 'Inactive')
                        : $changes[$field];
                    $delta = in_array($field, ['available', 'price', 'weight_g'], true)
                        ? (float) $changes[$field] - (float) ($current[$field] ?? 0) : '';

                    InventoryData::logMovement([
                        'sku' => $existing->sku,
                        'product_name' => $changes['name'] ?? $existing->name,
                        'field' => $field,
                        'old_value' => $oldValue, 'new_value' => $newValue, 'delta' => $delta,
                        'reason' => self::IMPORT_LOG_REASONS[$field] ?? 'Product details updated',
                        'notes' => $importNote, 'user' => $user,
                    ]);
                }

                $applied = array_intersect_key($changes, array_flip($differing));

                try {
                    InventoryData::applyChanges($existing, $applied);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Almost always a duplicate product_code or sku. Same
                    // treatment as a size clash above.
                    $failed[] = $entry['sku'].': that row could not be saved ('.$e->getMessage().')';

                    continue;
                }

                $updated++;
                $fieldChanges += count($differing);
            }
        });

        return response()->json([
            'rowsRead' => count($parsed['rows']),
            'created' => $created,
            'updated' => $updated,
            'fieldChanges' => $fieldChanges,
            'unchanged' => count($unchanged),
            'notFound' => $notFound,
            'missingName' => $missingName,
            'skippedExample' => $skippedExample,
            'sheetName' => $parsed['sheetName'],
            // ADDED to the reference's shape. Rows that could not be applied at
            // all — a size the design already stocks, a product_code another
            // design owns. The reference let these throw, which rolled back
            // every good row in the file with them.
            'failed' => $failed,
        ]);
    }

    /**
     * POST /inventory/photo — upload a single product photo.
     *
     * Stored on the `public` disk under products/, which is where the shop's own
     * seeder and ProductResource already look, and only the FILENAME comes back —
     * the frontend appends it to its own media base, and the pending-edits queue
     * validates queued photo values as bare filenames.
     */
    public function photo(Request $request)
    {
        $file = $request->file('photo');
        if ($file === null) {
            return response()->json(['error' => 'No photo uploaded.'], 400);
        }

        // The extension comes from the DETECTED content type, never from the
        // client's filename. Sniffing the MIME but then trusting the uploaded
        // name's extension is how image bytes with a .php name land executable
        // inside a public directory. Whitelist only.
        $allowedTypes = [
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/gif' => '.gif',
        ];
        $mime = (string) $file->getMimeType();
        if (! isset($allowedTypes[$mime])) {
            return response()->json(['error' => 'Only JPG, PNG, WEBP, or GIF images are allowed.'], 400);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return response()->json(['error' => 'Photo too large (5 MB limit).'], 400);
        }

        $ext = $allowedTypes[$mime];
        $original = (string) $file->getClientOriginalName();
        $base = (string) preg_replace('/[^a-z0-9_-]+/i', '-', pathinfo($original, PATHINFO_FILENAME));
        // The pending-edits queue validates photo filenames against
        // ^[A-Za-z0-9][A-Za-z0-9._-]*$ — a name like "_hero.png", or one that
        // sanitised down to only dashes, would be rejected right after upload.
        $base = ltrim($base, '-_');
        $base = substr($base, 0, 40) !== '' ? substr($base, 0, 40) : 'product';
        $filename = $base.'-'.round(microtime(true) * 1000).$ext;

        Storage::disk('public')->putFileAs(InventoryData::IMAGE_DIR, $file, $filename);

        return response()->json([
            'image' => $filename,
            // ADDED: where it actually resolves on this backend, so a caller
            // does not have to know how the storefront builds media URLs.
            // asset(), matching InventoryData::rows() and the shop's ProductResource.
            // Storage::disk('public')->url() builds from config APP_URL, so an upload
            // came back pointing at whatever APP_URL says rather than at the host the
            // staff member is actually using.
            'image_url' => asset('storage/'.InventoryData::IMAGE_DIR.'/'.$filename),
        ]);
    }

    /**
     * POST /inventory/product — create a single SKU (the "Add Product" form).
     *
     * In the reference this inserted one row. Here it either adds a SIZE to a
     * design that already exists or creates a whole new design — see
     * InventoryData::insertProduct for how that is decided. Either way the new
     * SKU lands Inactive so its price, photo and product page can be finished
     * before anyone activates it from the Catalog.
     */
    public function createProduct(Request $request)
    {
        $sku = trim((string) $request->input('sku', ''));
        $name = trim((string) $request->input('name', ''));

        if ($sku === '' || $name === '') {
            return response()->json(['error' => 'SKU and product name are required.'], 400);
        }

        if (InventoryData::findBySku($sku) !== null) {
            return response()->json(['error' => 'A product with SKU "'.$sku.'" already exists. Use Update mode instead.'], 409);
        }

        // Validated up front, with the same message the reference used, so a bad
        // marketplace is reported rather than silently dropped on create.
        $marketplace = trim((string) $request->input('marketplace', ''));
        if ($marketplace !== '' && ! in_array($marketplace, InventoryData::VALID_MARKETPLACES, true)) {
            return response()->json(['error' => 'Marketplace must be one of: '.implode(', ', InventoryData::VALID_MARKETPLACES)], 400);
        }

        $user = $this->actor($request);

        try {
            DB::transaction(function () use ($sku, $name, $request, $user) {
                InventoryData::insertProduct($sku, $name, $request->all(), $user);
            });
        } catch (\RuntimeException $e) {
            // The design already stocks that size.
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'error' => 'Could not create '.$sku.'. Its SKU or product code may already be in use.',
            ], 409);
        }

        $newRow = InventoryData::findBySku($sku);

        // A SKU created a moment ago cannot be on an order yet.
        return response()->json(
            InventoryData::presentProduct($newRow) + ['order_allocated' => 0, 'cancelled' => 0],
            201
        );
    }

    /**
     * PUT /inventory/{sku} — edit one SKU.
     *
     * A quantity change is the auditable action and demands a reason. Everything
     * else saves without one, but every field that moves still leaves an
     * Activity Log row: warehousing edits included, because "who moved this
     * pallet and when" is precisely what the log exists to answer.
     */
    public function update(Request $request, string $sku)
    {
        DB::transaction(function () use ($request, $sku) {
            $locked = DB::table('storefront_product_variants')->where('sku', $sku)->lockForUpdate()->first();
            $row = $locked === null ? null : InventoryData::findBySku($sku);
            if ($row === null) {
                $this->fail(404, 'SKU not found: '.$sku);
            }

            $reason = trim((string) $request->input('reason', ''));
            $notes = trim((string) $request->input('notes', ''));
            $user = $this->actor($request);

            // Only whitelisted product fields are copied; audit metadata (reason,
            // notes, user) never reaches a table. normaliseChanges also puts
            // every value into presented space, so the comparisons below are
            // like-for-like and a re-save of untouched fields is a no-op.
            try {
                $changes = InventoryData::normaliseChanges($request->all());
            } catch (\InvalidArgumentException $e) {
                $this->fail(400, $e->getMessage());
            }

            $current = InventoryData::presentProduct($row);
            $differing = InventoryData::differingFields($changes, $current);

            if ($differing === []) {
                return;
            }

            $changed = fn (string $field) => in_array($field, $differing, true);

            // A quantity change needs a reason from the fixed list. Price edits
            // are logged too, but never blocked.
            if ($changed('available')) {
                if ($reason === '') {
                    $this->fail(400, 'A reason is required for this adjustment.');
                }
                if (! in_array($reason, InventoryData::VALID_REASONS, true)) {
                    $this->fail(400, 'Reason must be one of: '.implode(', ', InventoryData::VALID_REASONS));
                }
                if ($reason === 'Other' && $notes === '') {
                    $this->fail(400, 'Please specify the reason when choosing "Other".');
                }

                InventoryData::logMovement([
                    'sku' => $row->sku, 'product_name' => $row->name, 'field' => 'available',
                    'old_value' => (int) $current['available'], 'new_value' => (int) $changes['available'],
                    'delta' => (int) $changes['available'] - (int) $current['available'],
                    'reason' => $reason, 'notes' => $notes, 'user' => $user,
                ]);
            }

            if ($changed('price')) {
                InventoryData::logMovement([
                    'sku' => $row->sku, 'product_name' => $row->name, 'field' => 'price',
                    'old_value' => (float) $current['price'], 'new_value' => (float) $changes['price'],
                    'delta' => (float) $changes['price'] - (float) $current['price'],
                    'reason' => $reason !== '' ? $reason : 'Price update',
                    'notes' => $notes, 'user' => $user,
                ]);
            }

            if ($changed('active')) {
                $newActive = (bool) $changes['active'];
                InventoryData::logMovement([
                    'sku' => $row->sku, 'product_name' => $row->name, 'field' => 'active',
                    'old_value' => $current['active'] ? 'Active' : 'Inactive',
                    'new_value' => $newActive ? 'Active' : 'Inactive', 'delta' => '',
                    'reason' => $reason !== '' ? $reason : ($newActive ? 'Reactivated' : 'Deactivated'),
                    'notes' => $notes, 'user' => $user,
                ]);
            }

            if ($changed('marketplace')) {
                InventoryData::logMovement([
                    'sku' => $row->sku, 'product_name' => $row->name, 'field' => 'marketplace',
                    'old_value' => $current['marketplace'] ?? 'TikTok', 'new_value' => $changes['marketplace'], 'delta' => '',
                    'reason' => $reason !== '' ? $reason : ('Moved to '.$changes['marketplace']),
                    'notes' => $notes, 'user' => $user,
                ]);
            }

            /*
             * The remaining fields. The first five are the reference's
             * warehousing set; the last four are ADDED, and deliberately.
             *
             * The reference logged a name, category, size or product_code change
             * only when it came through the Excel import — edit the same field in
             * the detail panel and nothing was recorded. That was survivable
             * against a private ERP table. Here those four are edits to the LIVE
             * catalogue (three of them rename or recategorise every size of a
             * design at once), so an unlogged one is exactly the change nobody
             * can explain afterwards. The Activity Log's own field filter
             * already offers all four; it simply never had anything to show.
             */
            $detailFields = [
                'location' => 'Shelf updated',
                'warehouse' => 'Warehouse updated',
                'area' => 'Area updated',
                'weight_g' => 'Weight updated',
                'dimensions' => 'Size (cm) updated',
                'name' => 'Name updated',
                'category' => 'Category updated',
                'size' => 'Size updated',
                'product_code' => 'Product code updated',
                // Also added. A photo swap changes what shoppers see on the
                // storefront within a minute; it should not be the one edit with
                // no record of who made it.
                'image' => 'Photo updated',
            ];

            foreach ($detailFields as $field => $fallbackReason) {
                if (! $changed($field)) {
                    continue;
                }
                $oldValue = (string) ($current[$field] ?? '');
                $newValue = (string) $changes[$field];

                InventoryData::logMovement([
                    'sku' => $row->sku, 'product_name' => $row->name, 'field' => $field,
                    'old_value' => $oldValue !== '' ? $oldValue : '—',
                    'new_value' => $newValue !== '' ? $newValue : '—',
                    'delta' => $field === 'weight_g'
                        ? (float) $changes[$field] - (float) ($current[$field] ?? 0) : '',
                    'reason' => $reason !== '' ? $reason : $fallbackReason,
                    'notes' => $notes, 'user' => $user,
                ]);
            }

            // Only the fields that moved are written — never the whole row. See
            // InventoryData::applyChanges for why that matters on a live shop.
            $applied = array_intersect_key($changes, array_flip($differing));

            try {
                InventoryData::applyChanges($row, $applied);
            } catch (\Illuminate\Database\QueryException $e) {
                $this->fail(409, 'Could not save '.$sku.'. Its product code may already belong to another design.');
            }
        });

        // The grid replaces its cached row wholesale with this response, so the
        // two derived order columns have to come back with it.
        $updated = InventoryData::findBySku($sku);

        return response()->json(InventoryData::presentProduct($updated));
    }

    /**
     * DELETE /inventory/{sku} — remove one SKU from the catalogue.
     *
     * THIS IS THE DESTRUCTIVE PATH AND IT IS NOT THE REFERENCE'S. There, a
     * delete removed a row from a private inventory table. Here the same button
     * reaches into a live shop, so three rules are added:
     *
     *   1. It refuses (409) while units are ALLOCATED against open orders.
     *      Deleting then would destroy the reservation, and nothing downstream
     *      could fulfil, release or refund those units — the shop would simply
     *      have promised stock it no longer models. The message names the
     *      number, so the operator knows to clear the orders first.
     *   2. It deletes the VARIANT only. The `products` row survives even when
     *      this was its last size: deleting a product cascades into
     *      cart_items and stock_alerts and orphans order_items' product_id, and
     *      an ERP delete of one SKU never meant "and destroy the design".
     *   3. Removing the last size DEACTIVATES the design instead, so the
     *      storefront stops listing a product that can no longer be bought.
     *
     * Past orders are unaffected either way: order_items snapshots name, size
     * and price and has no foreign key to product_variants, so history stays
     * readable after the SKU is gone.
     */
    public function destroy(Request $request, string $sku)
    {
        $deleted = DB::transaction(function () use ($request, $sku) {
            $locked = DB::table('storefront_product_variants')->where('sku', $sku)->lockForUpdate()->first();
            $row = $locked === null ? null : InventoryData::findBySku($sku);
            if ($row === null) {
                $this->fail(404, 'SKU not found: '.$sku);
            }

            $allocated = (int) $row->allocated;
            if ($allocated > 0) {
                $this->fail(409, 'Cannot delete '.$sku.': '.$allocated.' unit'.($allocated === 1 ? ' is' : 's are')
                    .' reserved against open orders. Ship or cancel those orders first, or set it Inactive instead.');
            }

            $presented = InventoryData::presentProduct($row);

            InventoryData::logMovement([
                'sku' => $row->sku, 'product_name' => $row->name, 'field' => 'deleted',
                'old_value' => (int) $row->on_hand, 'new_value' => 0,
                'delta' => -((int) $row->on_hand),
                'reason' => 'Stock correction', 'notes' => 'SKU deleted from catalog',
                'user' => $this->actor($request),
            ]);

            DB::table('storefront_product_variants')->where('id', $row->id)->delete();

            $remaining = DB::table('storefront_product_variants')->where('product_id', $row->product_id)->count();
            if ($remaining === 0) {
                DB::table('storefront_products')->where('id', $row->product_id)->update([
                    'is_active' => 0,
                    'updated_at' => now(),
                ]);

                InventoryData::logMovement([
                    'sku' => InventoryData::productCode($row), 'product_name' => $row->name, 'field' => 'active',
                    'old_value' => $row->product_active ? 'Active' : 'Inactive', 'new_value' => 'Inactive', 'delta' => '',
                    'reason' => 'Deactivated',
                    'notes' => 'Last size ('.$sku.') deleted — the design has nothing left to sell',
                    'user' => $this->actor($request),
                ]);
            }

            return $presented;
        });

        return response()->json(['deleted' => $deleted]);
    }
}
