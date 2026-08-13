<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Push Product" review queue.
 *
 * Manual price / status edits from the Product Catalog, manual On Hand
 * corrections from the Inventory detail panel, and website-content edits from
 * the Catalog's Website View land HERE instead of writing to the shop's tables
 * directly. A scheduled command applies every row in one batch at 12:00 AM
 * Asia/Manila and clears the queue; rows can also be force-pushed early, or
 * discarded, from the Push Product modal.
 *
 * Retained even though this module writes to the shop's own tables rather than
 * to a separate website database, because the queue was never about the second
 * database — it is a review gate. Catalog.jsx is moving over unchanged and its
 * status pills, "→ ₱X queued" hints, "Queued by <name>" tooltips and the whole
 * Push Product modal all read these rows. Applying a row now writes straight to
 * products / product_variants and logs to stock_inventory_log.
 *
 * ONE ROW PER (sku, field). Re-editing the same field before it is pushed
 * OVERWRITES the pending row — last edit wins, no history stacking. History
 * only exists in stock_inventory_log, written when a row is actually applied.
 * That rule is enforced by the unique index below, not merely in code.
 *
 * Shape is the live ash_erp_v2.pending_product_edits DDL, already carrying the
 * widening the reference applied afterwards (2026_08_12_000001 turned
 * old_value/new_value into TEXT NOT NULL) — there is no populated table to
 * migrate here, so they are created in their final form.
 *
 * Keyed on sku as a plain indexed varchar, no FK, for the same reasons as
 * stock_inventory_log: the column is dual-purpose (a product_variants.sku for
 * per-variant fields, a products.product_code for website-content fields), and
 * apply-time deliberately DROPS a pending row whose SKU no longer exists rather
 * than letting the database refuse the delete. Width is 255 to match
 * product_variants.sku exactly; the unique index costs 255*4 + 32*4 = 1148
 * bytes, inside InnoDB's 3072-byte DYNAMIC key limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_stock_pending_product_edits', function (Blueprint $table) {
            $table->id();

            // product_variants.sku, or products.product_code for content fields.
            $table->string('sku', 255);

            // Snapshot for display, so the queue still reads sensibly if the
            // product is renamed (or removed) before the push runs.
            $table->string('product_name')->nullable();

            // 'price' | 'active' | 'available' for per-variant edits, or one of
            // the website-content fields. 32 rather than the ERP's 20 for the
            // same reason as stock_inventory_log.field — reefer_db's column
            // names are longer, and a truncating INSERT under strict mode would
            // silently mis-key the unique(sku, field) constraint.
            $table->string('field', 32);

            // Canonical stored strings ('active' is '1'/'0'); old_value is the
            // LIVE value at the time of the edit, which is what the modal shows
            // as "from". TEXT because content edits queue full paragraphs, and
            // TEXT cannot carry a DEFAULT in MySQL — but both values are always
            // written explicitly, so NOT NULL alone is enough.
            $table->text('old_value');
            $table->text('new_value');

            // On Hand edits carry the mandatory audit reason (+ notes when
            // "Other") collected by the Inventory panel, so the midnight apply
            // can write the same stock_inventory_log entry a direct save would.
            $table->string('reason', 100)->nullable();
            $table->string('notes', 500)->nullable();

            $table->string('edited_by', 100)->default('unknown');

            // Stamped Asia/Manila as a string by the queueing code, not by the
            // DB clock. Do NOT cast this in the model — see PendingProductEdit.
            $table->dateTime('edited_at');

            // Last-edit-wins, enforced here and not just in code.
            $table->unique(['sku', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_stock_pending_product_edits');
    }
};
