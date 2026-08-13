<?php

namespace App\Support\Storefront\Stock;

use App\Models\Storefront\Stock\PendingProductEdit;
use Illuminate\Support\Facades\DB;

/**
 * The "Push Product" queued-edit system.
 *
 * Two kinds of edit queue here instead of writing straight through:
 *
 *   Per-SKU fields, keyed by product_variants.sku
 *     price     — edited on the Product Catalog screen (per DESIGN in reefer_db)
 *     active    — toggled on the Product Catalog screen (per SIZE)
 *     available — corrected on the Inventory detail panel (On Hand)
 *
 *   Per-DESIGN website content, keyed by products.product_code
 *     the copy and photos the storefront renders on a product page, edited from
 *     the Catalog's Website View.
 *
 * NOTHING else goes through this queue. Order-driven stock movement, the Excel
 * bulk import, the Import Stock form and every storefront sync keep writing
 * directly, exactly as in the reference.
 *
 * WHAT CHANGED IN THE PORT. The reference applied a row by writing to its own
 * `inventory` / `product_content` tables, which a separate website database then
 * synced from. There is one database here, so applying a row writes to the
 * SHOP'S OWN `products` and `product_variants` — a push is live on
 * reeferclothing.com the moment it lands, with no sync window in between. The
 * queue survives the port because it was never really about the second database:
 * it is a review gate, and Catalog.jsx's status pills, "→ ₱X queued" hints and
 * the whole Push Product modal are built on it.
 *
 * Rows are applied — and removed — by the scheduled midnight command
 * (stock:push-pending-product-edits, 12:00 AM Asia/Manila) or by a Force Push.
 * Applying a row writes the same stock_inventory_log entry the equivalent direct
 * save would have written, attributed to whoever queued it.
 */
class PendingProductEdits
{
    public const TABLE = 'storefront_stock_pending_product_edits';

    public const FIELDS = ['price', 'active', 'available'];

    /**
     * Website-content fields, one value per design.
     *
     * Kept at all fourteen because Catalog.jsx renders a row per field and reads
     * every key off the `website` object. Only EIGHT have a column in reefer_db
     * (see InventoryData::CONTENT_COLUMNS); the other six — color, print_method,
     * care, origin, image_back, image_detail — read back as null and are refused
     * at queue time with a message that says so, rather than being accepted and
     * dropped at push time. An edit that vanishes silently is worse than one
     * that is turned away.
     */
    public const CONTENT_FIELDS = [
        'tag', 'audience', 'type', 'color', 'blurb', 'material', 'print_method',
        'care', 'origin', 'fit_name', 'fit_desc',
        'image_front', 'image_back', 'image_detail',
    ];

    /** Content fields this database can actually store. */
    public const SUPPORTED_CONTENT_FIELDS = [
        'tag', 'audience', 'type', 'blurb', 'material', 'fit_name', 'fit_desc', 'image_front',
    ];

    /**
     * products.audience and products.type are NOT NULL, so unlike every other
     * content field these two cannot be cleared back to "site keeps its own".
     */
    public const REQUIRED_CONTENT_FIELDS = ['audience', 'type'];

    public const CONTENT_AUDIENCES = InventoryData::VALID_AUDIENCES;

    /** The union of both apps' vocabularies — see InventoryData::VALID_TYPES. */
    public const CONTENT_TYPES = InventoryData::VALID_TYPES;

    /**
     * The colorway palette the storefront's filter groups on. Fixed values, not
     * free text — "charcoal", "CHARCOAL" and "dark gray" must all land in one
     * bucket for the filter counts to mean anything.
     */
    public const CONTENT_COLORS = ['black', 'white', 'gray', 'beige', 'navy', 'blue', 'red', 'orange', 'green', 'multi'];

    /** Activity-log reason per content field when the editor gave none. */
    public const CONTENT_LOG_REASONS = [
        'tag' => 'Website tag updated',
        'audience' => 'Website audience updated',
        'type' => 'Website product type updated',
        'color' => 'Website colorway updated',
        'blurb' => 'Website description updated',
        'material' => 'Website fabric details updated',
        'print_method' => 'Website print details updated',
        'care' => 'Website care details updated',
        'origin' => 'Website origin details updated',
        'fit_name' => 'Website fit name updated',
        'fit_desc' => 'Website fit description updated',
        'image_front' => 'Website front photo updated',
        'image_back' => 'Website back photo updated',
        'image_detail' => 'Website detail photo updated',
    ];

    public static function isContentField(string $field): bool
    {
        return in_array($field, self::CONTENT_FIELDS, true);
    }

    public static function isSupportedContentField(string $field): bool
    {
        return in_array($field, self::SUPPORTED_CONTENT_FIELDS, true);
    }

    /**
     * Present the queue for the API: numeric id, everything else as the stored
     * strings (the frontend formats per field).
     */
    public static function listAll(): array
    {
        return PendingProductEdit::query()->newestFirst()->get()
            ->map(function ($row) {
                $out = $row->toArray();
                $out['id'] = (int) $row->id;

                return (object) $out;
            })
            ->all();
    }

    /**
     * Normalise a raw client value into the canonical stored string for a field,
     * so "120", "120.0" and 120 cannot produce distinct pending rows.
     *
     * `price` additionally rounds to whole pesos, because products.price is an
     * unsigned INT: queueing 699.50 and then storing 700 would leave the modal
     * promising a number the push cannot deliver, and the next queue would read
     * its own old_value as different and re-queue forever.
     */
    public static function normalise(string $field, mixed $value): string
    {
        if (self::isContentField($field)) {
            return trim((string) $value);
        }

        return match ($field) {
            'price' => (string) (float) max(0, (int) round((float) $value)),
            'available' => (string) max(0, (int) $value),
            'active' => filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0',
        };
    }

    /** The live row's current value for a field, in the same canonical form. */
    private static function liveValue(object $row, string $field): string
    {
        return match ($field) {
            'price' => (string) (float) (int) $row->price,
            'available' => (string) (int) $row->on_hand,
            'active' => ((bool) $row->variant_active) ? '1' : '0',
        };
    }

    /** The live website-content value for one design+field ('' when unset). */
    private static function liveContentValue(?object $product, string $field): string
    {
        if ($product === null) {
            return '';
        }

        $column = InventoryData::CONTENT_COLUMNS[$field] ?? null;
        if ($column === null) {
            return '';
        }

        $value = trim((string) ($product->$column ?? ''));

        // image_front is stored as a path but queued and shown as a filename.
        return ($field === 'image_front' && $value !== '') ? basename($value) : $value;
    }

    /**
     * Queue (or overwrite) the pending edit for one field.
     *
     * Last-edit-wins: a second edit to the same sku+field replaces the row.
     * Re-entering the value already live on file CLEARS any pending row instead
     * of storing a no-op — typing the old value back is an undo.
     *
     * Caller validates field/value/reason and wraps this in a transaction.
     *
     * @return array{status: 'queued'|'cleared', old_value?: string, new_value?: string}
     */
    public static function upsert(string $sku, string $field, mixed $newValue, string $editedBy, string $reason = '', string $notes = ''): array
    {
        if (self::isContentField($field)) {
            return self::upsertContent($sku, $field, $newValue, $editedBy, $reason, $notes);
        }

        // Lock the variant, then read the joined row: a join cannot be locked
        // portably, and the variant is the row a concurrent push would move.
        $locked = DB::table('storefront_product_variants')->where('sku', $sku)->lockForUpdate()->first();
        if ($locked === null) {
            throw new \InvalidArgumentException('SKU not found: '.$sku);
        }
        $row = InventoryData::findBySku($sku);
        if ($row === null) {
            throw new \InvalidArgumentException('SKU not found: '.$sku);
        }

        $new = self::normalise($field, $newValue);
        $old = self::liveValue($row, $field);

        if ($new === $old) {
            PendingProductEdit::query()->forField($sku, $field)->delete();

            return ['status' => 'cleared'];
        }

        self::write($sku, $field, $row->name, $old, $new, $reason, $notes, $editedBy);

        return ['status' => 'queued', 'old_value' => $old, 'new_value' => $new];
    }

    /**
     * Queue (or overwrite) one website-content edit. $productCode identifies the
     * DESIGN — all sizes share the content — and the queue row stores it in the
     * `sku` column, same as the reference.
     *
     * @return array{status: 'queued'|'cleared', old_value?: string, new_value?: string}
     */
    private static function upsertContent(string $productCode, string $field, mixed $newValue, string $editedBy, string $reason, string $notes): array
    {
        // Resolved through InventoryData so the synthetic 'P0007' code the grid
        // shows for a design with no product_code of its own resolves here too —
        // otherwise most of the 18 live designs could never have their website
        // copy edited at all.
        $design = InventoryData::findDesignByCode($productCode);
        if ($design === null) {
            throw new \InvalidArgumentException('Product code not found: '.$productCode);
        }
        $product = DB::table('storefront_products')->where('id', $design->id)->lockForUpdate()->first();
        if ($product === null) {
            throw new \InvalidArgumentException('Product code not found: '.$productCode);
        }

        $new = self::normalise($field, $newValue);
        $old = self::liveContentValue($product, $field);

        if ($new === $old) {
            PendingProductEdit::query()->forField($productCode, $field)->delete();

            return ['status' => 'cleared'];
        }

        self::write($productCode, $field, $product->name, $old, $new, $reason, $notes, $editedBy);

        return ['status' => 'queued', 'old_value' => $old, 'new_value' => $new];
    }

    /** One upsert against the unique(sku, field) index. */
    private static function write(string $sku, string $field, ?string $productName, string $old, string $new, string $reason, string $notes, string $editedBy): void
    {
        PendingProductEdit::query()->updateOrCreate(
            ['sku' => $sku, 'field' => $field],
            [
                'product_name' => $productName !== null ? mb_substr($productName, 0, 255) : null,
                'old_value' => $old,
                'new_value' => $new,
                'reason' => $reason !== '' ? mb_substr($reason, 0, 100) : null,
                'notes' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
                'edited_by' => mb_substr($editedBy !== '' ? $editedBy : 'unknown', 0, 100),
                // Business timestamps stamp in Manila, same as order dates.
                'edited_at' => now('Asia/Manila')->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Apply ONE pending row to the live tables and delete it from the queue.
     * Must run inside a transaction.
     *
     * Mirrors InventoryController::update() semantics per field, including the
     * "no stock means not Active" rule, and writes the identical
     * stock_inventory_log entry a direct save would have — attributed to the
     * human who queued it, with a note marking which push applied it.
     *
     * @param  'scheduled'|'forced'  $trigger
     * @return 'applied'|'no_change'|'sku_gone'
     */
    public static function applyOne(object $pending, string $trigger): string
    {
        $deleteSelf = fn () => DB::table(self::TABLE)->where('id', $pending->id)->delete();

        if (self::isContentField($pending->field)) {
            return self::applyContentOne($pending, $trigger, $deleteSelf);
        }

        $locked = DB::table('storefront_product_variants')->where('sku', $pending->sku)->lockForUpdate()->first();
        $row = $locked === null ? null : InventoryData::findBySku($pending->sku);
        if ($row === null) {
            // The SKU was deleted while the edit sat in the queue — nothing to
            // apply it to.
            $deleteSelf();

            return 'sku_gone';
        }

        $field = $pending->field;
        $new = (string) $pending->new_value;
        $liveOld = self::liveValue($row, $field);

        // The live table caught up on its own (an order drained stock to exactly
        // the queued number) — drop the row, log nothing.
        if ($liveOld === $new) {
            $deleteSelf();

            return 'no_change';
        }

        $marker = $trigger === 'forced'
            ? 'Push Product — force push'
            : 'Push Product — scheduled 12:00 AM push';
        $notes = trim((string) ($pending->notes ?? ''));
        $logNotes = $notes !== '' ? $notes.' · '.$marker : $marker;
        $reason = trim((string) ($pending->reason ?? ''));
        $user = (string) $pending->edited_by;

        if ($field === 'price') {
            // products.price is per DESIGN: this moves every size of it. That is
            // the shop's schema, not a shortcut — see InventoryData's header.
            DB::table('storefront_products')->where('id', $row->product_id)->update([
                'price' => max(0, (int) round((float) $new)),
                'updated_at' => now(),
            ]);
            InventoryData::logMovement([
                'sku' => $row->sku, 'product_name' => $row->name, 'field' => 'price',
                'old_value' => (float) $liveOld, 'new_value' => (float) $new,
                'delta' => (float) $new - (float) $liveOld,
                'reason' => $reason !== '' ? $reason : 'Price update',
                'notes' => $logNotes, 'user' => $user,
            ]);
        } elseif ($field === 'active') {
            $newActive = $new === '1';
            // Same rule as every other write path: a SKU with zero on hand can
            // never end up Active.
            if ((int) $row->on_hand === 0) {
                $newActive = false;
            }
            if ($newActive === (bool) $row->variant_active) {
                $deleteSelf();

                return 'no_change';
            }
            DB::table('storefront_product_variants')->where('id', $row->id)->update([
                'is_active' => $newActive ? 1 : 0,
                'updated_at' => now(),
            ]);
            // Publish the DESIGN, not just the size. This pill is what the create
            // path's docblock has always pointed staff at — "somebody deliberately
            // activates from the Catalog" — but it only ever wrote
            // product_variants.is_active, while the storefront also filters on
            // products.is_active. Without this line, activating every size of a
            // stock-manager design still left it invisible to customers.
            InventoryData::syncProductActive((int) $row->product_id);
            InventoryData::logMovement([
                'sku' => $row->sku, 'product_name' => $row->name, 'field' => 'active',
                'old_value' => ((bool) $row->variant_active) ? 'Active' : 'Inactive',
                'new_value' => $newActive ? 'Active' : 'Inactive', 'delta' => '',
                'reason' => $reason !== '' ? $reason : ($newActive ? 'Reactivated' : 'Deactivated'),
                'notes' => $logNotes, 'user' => $user,
            ]);
        } else { // available
            $newOnHand = max(0, (int) $new);
            $update = ['on_hand' => $newOnHand, 'updated_at' => now()];
            if ($newOnHand === 0) {
                $update['is_active'] = 0;
            }
            DB::table('storefront_product_variants')->where('id', $row->id)->update($update);
            // Draining the last live size takes the design off the shop, rather
            // than leaving a product page on which every size is struck through.
            //
            // Note this does NOT re-activate on restock: a size deactivated by a
            // stock-out is indistinguishable from one a human switched off, and
            // silently republishing the latter is the worse mistake. Restocking
            // plus the status pill brings it back.
            InventoryData::syncProductActive((int) $row->product_id);
            InventoryData::logMovement([
                'sku' => $row->sku, 'product_name' => $row->name, 'field' => 'available',
                'old_value' => (int) $liveOld, 'new_value' => $newOnHand,
                'delta' => $newOnHand - (int) $liveOld,
                'reason' => $reason !== '' ? $reason : 'Stock correction',
                'notes' => $logNotes, 'user' => $user,
            ]);
        }

        $deleteSelf();

        return 'applied';
    }

    /**
     * Apply ONE website-content row: write the value onto the design's
     * `products` row and log it. The queue row's `sku` column carries the
     * product_code.
     *
     * @param  'scheduled'|'forced'  $trigger
     * @return 'applied'|'no_change'|'sku_gone'
     */
    private static function applyContentOne(object $pending, string $trigger, callable $deleteSelf): string
    {
        $productCode = (string) $pending->sku;
        $field = (string) $pending->field;

        $design = InventoryData::findDesignByCode($productCode);
        $product = $design === null
            ? null
            : DB::table('storefront_products')->where('id', $design->id)->lockForUpdate()->first();

        if ($product === null) {
            // Design deleted, or its code changed, while the edit sat queued.
            $deleteSelf();

            return 'sku_gone';
        }

        $column = InventoryData::CONTENT_COLUMNS[$field] ?? null;
        if ($column === null) {
            // Unreachable through the API — the queue endpoint refuses these —
            // but a row could survive a rollback of column support. Drop it
            // rather than throwing it back on the queue every single night.
            $deleteSelf();

            return 'sku_gone';
        }

        $new = trim((string) $pending->new_value);
        $liveOld = self::liveContentValue($product, $field);

        if ($liveOld === $new) {
            $deleteSelf();

            return 'no_change';
        }

        // '' clears the field back to NULL and the storefront falls back to its
        // own copy — except for the two NOT NULL columns, which the queue
        // endpoint never lets reach this point empty.
        $stored = $new !== '' ? $new : null;
        if ($field === 'image_front') {
            $stored = InventoryData::imagePath($new);
        }

        DB::table('storefront_products')->where('id', $product->id)->update([
            $column => $stored,
            'updated_at' => now(),
        ]);

        $marker = $trigger === 'forced'
            ? 'Push Product — force push'
            : 'Push Product — scheduled 12:00 AM push';
        $notes = trim((string) ($pending->notes ?? ''));
        $reason = trim((string) ($pending->reason ?? ''));

        InventoryData::logMovement([
            'sku' => $productCode, 'product_name' => $product->name, 'field' => $field,
            'old_value' => $liveOld !== '' ? $liveOld : '—',
            'new_value' => $new !== '' ? $new : '—', 'delta' => '',
            'reason' => $reason !== '' ? $reason : (self::CONTENT_LOG_REASONS[$field] ?? 'Website content updated'),
            'notes' => $notes !== '' ? $notes.' · '.$marker : $marker,
            'user' => (string) $pending->edited_by,
        ]);

        $deleteSelf();

        return 'applied';
    }

    /**
     * Apply every pending row (the midnight batch, or "Force Push All").
     *
     * Each row runs in its own transaction so one bad row cannot strand the rest
     * of the queue; failed rows STAY queued and are reported.
     *
     * @param  'scheduled'|'forced'  $trigger
     * @return array{applied: int, no_change: int, sku_gone: int, failed: array<int, string>}
     */
    public static function applyAll(string $trigger): array
    {
        $summary = ['applied' => 0, 'no_change' => 0, 'sku_gone' => 0, 'failed' => []];

        $rows = DB::table(self::TABLE)->orderBy('id')->get();
        foreach ($rows as $pending) {
            try {
                $result = DB::transaction(fn () => self::applyOne($pending, $trigger));
                $summary[$result]++;
            } catch (\Throwable $e) {
                $summary['failed'][] = $pending->sku.'/'.$pending->field.': '.$e->getMessage();
            }
        }

        return $summary;
    }
}
