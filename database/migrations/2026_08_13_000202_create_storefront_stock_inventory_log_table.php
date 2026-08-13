<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Activity Log: append-only audit trail of every field change on a product
 * or variant.
 *
 * This is one of only three tables the Stock manager module owns, and the
 * reason it exists is that `products` and `product_variants` carry no history
 * at all — they have created_at/updated_at and nothing else. "Who changed this,
 * from what, to what, when, and why" is the entire point of an ERP, and today
 * reefer_db cannot answer any of it. Every write the module makes to the shop's
 * tables writes a row here in the same transaction.
 *
 * Shape is the live ash_erp_v2.inventory_log DDL, already carrying the two
 * widenings the reference applied afterwards (2026_08_12_000002 turned
 * old_value/new_value into TEXT) — there is no populated table to migrate here,
 * so they are created in their final form.
 *
 * KEYED ON SKU, NOT ON A FOREIGN KEY. This is deliberate and it matches how the
 * ERP behaved, for three separate reasons:
 *
 *   1. A delete writes the 'deleted' audit row and removes the variant in the
 *      same transaction. ON DELETE RESTRICT would abort that; CASCADE would
 *      erase the audit trail — the one thing an audit trail must survive.
 *   2. The column is dual-purpose. For per-variant fields it holds a
 *      product_variants.sku; for the website-content fields it holds a
 *      products.product_code (a DESIGN, not a size). The frontend already
 *      copes with that — Catalog.jsx resolves a size from the sku when it can
 *      and shows the bare code when it cannot.
 *   3. The ERP tolerated rows whose SKU later vanished and handled it
 *      explicitly. That behaviour is part of the spec being ported.
 *
 * Width note: `sku` is varchar(255) here, not the ERP's varchar(100), so it can
 * hold anything product_variants.sku (varchar(255)) can. Live SKUs top out at
 * 22 chars, but a value the source column accepts and this one truncates would
 * fail the INSERT under strict mode and strand the write. The composite index
 * below costs 255*4 + 5 = 1025 bytes, well inside InnoDB's 3072-byte DYNAMIC
 * key limit.
 *
 * There are deliberately NO created_at / updated_at columns and no order_id or
 * source column: `timestamp` is the audit stamp, and the order number plus the
 * "scheduled vs forced push" marker are folded into the `notes` prose, exactly
 * as the reference does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_stock_inventory_log', function (Blueprint $table) {
            // Presented to users as CONCAT('LOG-', 1000 + id) — see
            // InventoryLog::scopeWithLogId().
            $table->id();

            // product_variants.sku, or products.product_code for content fields.
            // No FK — see the header.
            $table->string('sku', 255);

            // Snapshot for display; genuinely nullable (the logger writes a
            // literal null when the caller has no name to hand).
            $table->string('product_name')->nullable();

            // The column that changed: on_hand/available, allocated,
            // cancelled_qty, price, active, marketplace, shelf_location,
            // warehouse, area, weight_grams, dimensions, name, type, size,
            // product_code, audience, the pseudo-field 'deleted', or one of the
            // website-content fields (blurb, material, fit_name, fit_desc, ...).
            //
            // 32, not the ERP's 20: reefer_db's column names are longer than the
            // ERP's were ('shelf_location' is 14, 'cancelled_qty' 13) and a
            // truncating INSERT under strict mode would lose an audit row. The
            // frontend reads this as an opaque key, so widening cannot affect it.
            $table->string('field', 32);

            // Canonical string values. TEXT because website-content pushes carry
            // full paragraphs; TEXT cannot take a DEFAULT in MySQL, hence NULL.
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            // Signed numeric change as a STRING ('' for non-numeric fields,
            // which is why this cannot be a numeric column under strict mode).
            // Both consumers re-parse it with Number()/is_numeric.
            $table->string('delta', 50)->default('');

            // A value from the module's reason list, the import/content reason
            // maps, or free text from the request.
            $table->string('reason', 100);

            // Free prose: 'Excel import — <file>', 'Reserved for order
            // REEF-#####', and queued notes + the ' · Push Product — …' marker.
            // 600, not the 500 of stock_pending_product_edits.notes: applying a
            // queued row appends that ~41-char marker to notes already capped at
            // 500, and under strict mode the overflow would strand the queue row
            // as "failed" forever.
            $table->string('notes', 600)->default('');

            // 'system' for order-driven movement, the pending row's edited_by for
            // pushes, else the authenticated staff username. `user` is a
            // non-reserved MySQL keyword — backtick it in hand-written SQL.
            $table->string('user', 100)->default('unknown');

            // Self-populating: no code path writes it. Never ON UPDATE — audit
            // rows are immutable.
            $table->dateTime('timestamp')->useCurrent();

            // The Inventory detail panel's per-SKU history: where('sku', ...)
            // immediately followed by orderByDesc('timestamp').
            $table->index(['sku', 'timestamp']);

            // The unfiltered newest-first scans — the Activity Log page and the
            // xlsx export — which would otherwise filesort the whole table.
            $table->index('timestamp');

            // No unique constraint: append-only, many rows per (sku, field).
            // Deliberately unlike stock_pending_product_edits' unique(sku,field).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_stock_inventory_log');
    }
};
