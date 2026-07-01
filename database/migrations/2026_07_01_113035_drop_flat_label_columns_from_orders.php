<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the legacy flat label columns from orders.
 *
 * The order label spec is now the structured brand_label_json / care_label_json
 * / label_design_path trio (added in 2026_07_01_000001), matching the quotation.
 * These three flat columns are the OUTDATED order-form label mechanism and are
 * fully superseded:
 *
 *   - size_label            → replaced by the Brand/Care label spec
 *   - print_label_placement → replaced by each label's `placement`
 *   - size_label_files      → replaced by the shared `label_design_path` upload
 *
 * NOTE — this intentionally does NOT touch:
 *   - order_designs.size_label (a Graphic-Artist artwork FILE path — different
 *     table, different meaning)
 *   - the size_labels / print_label_placements lookup tables (they now feed the
 *     new spec's Method / Placement dropdowns)
 *   - OrderLabelAsset (main_label / size_label / hangtag production assets)
 *
 * The historical create/recreate migrations that first added these columns are
 * left untouched (they already ran on the live DB; editing them would not change
 * it). This additive drop converges live + fresh installs to the same end state.
 *
 * Guarded with hasColumn() so it is safe to run on any environment. down()
 * restores the columns as nullable for reversibility.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            foreach (['size_label', 'print_label_placement', 'size_label_files'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'size_label')) {
                $table->string('size_label')->nullable();
            }
            if (! Schema::hasColumn('orders', 'print_label_placement')) {
                $table->string('print_label_placement')->nullable();
            }
            if (! Schema::hasColumn('orders', 'size_label_files')) {
                $table->text('size_label_files')->nullable();
            }
        });
    }
};
