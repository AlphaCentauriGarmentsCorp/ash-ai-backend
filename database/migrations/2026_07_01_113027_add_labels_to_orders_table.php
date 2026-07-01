<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Brand Label + Care/Size Label spec to orders.
 *
 * Brings the order's label capture in line with the quotation's Issue-7 label
 * spec (see 2026_05_25_104759_add_labels_to_quotations_table) so a converted
 * quotation prefills cleanly and Add/Edit Order use the SAME structured shape
 * as Add/Edit Quotation. This REPLACES the old flat `size_label` +
 * `print_label_placement` columns (dropped in the companion migration
 * 2026_07_01_000002_drop_flat_label_columns_from_orders).
 *
 * Shape of brand_label_json / care_label_json (identical, mirroring the
 * quotation exactly):
 *   {
 *     "enabled":     bool,
 *     "material":    string|null,
 *     "method":      string|null,   // from size_labels dropdown
 *     "placement":   string|null,   // from print_label_placements
 *     "measurement": string|null,   // from placement_measurements (optional)
 *     "notes":       string|null
 *   }
 *
 * label_design_path is the ONE shared, optional design-file upload for the
 * label artwork — a storage path (public disk) OR an external link, mirroring
 * the quotation's label_design_path.
 *
 * Guarded with hasTable()/hasColumn() so it is safe to run on any environment.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Brand Label spec (material + method + placement). JSON blob.
            if (! Schema::hasColumn('orders', 'brand_label_json')) {
                $table->json('brand_label_json')->nullable()->after('print_service');
            }

            // Care/Size Label spec — same structure as Brand Label.
            if (! Schema::hasColumn('orders', 'care_label_json')) {
                $table->json('care_label_json')->nullable()->after('brand_label_json');
            }

            // ONE shared label-design upload (file path on public disk OR an
            // external link). Optional — labels can be specced without artwork.
            if (! Schema::hasColumn('orders', 'label_design_path')) {
                $table->string('label_design_path', 1000)->nullable()->after('care_label_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            foreach (['brand_label_json', 'care_label_json', 'label_design_path'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
