<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Brand Label + Care/Size Label spec to quotations (Blueprint Issue 7).
 *
 * Issue 7 adds a NEW label system to the quotation. It is a *spec captured at
 * quote time* (which label, what material/method/placement) — distinct from
 * the production-side OrderLabelAsset, which is a physical artwork asset with
 * its own lifecycle. Because each label is always read/written as one blob
 * with its parent quotation, we follow the table's existing pattern
 * (item_config_json, addons_json, print_parts_json, breakdown_json) and store
 * each label as a JSON column rather than normalizing into a child table.
 *
 * Shape of brand_label_json / care_label_json (both identical, mirroring each
 * other per the confirmed spec):
 *   {
 *     "enabled":      bool,
 *     "material":     string|null,   // e.g. "Woven Tag" | "DTF" | "Sublimation" | "Taffeta"
 *     "method":       string|null,   // from size_labels dropdown: "Sew" | "Print" | "None"
 *     "placement":    string|null,   // from print_label_placements: "nape" | "sleeves" | "hem" ...
 *     "measurement":  string|null,   // from placement_measurements (optional detail)
 *     "notes":        string|null
 *   }
 *
 * label_design_path is the ONE shared, optional design-file upload for the
 * label artwork — a storage path (public disk) OR an external link, mirroring
 * custom_pattern_image (Issue 6).
 *
 * Guarded with hasTable()/hasColumn() so it is safe to run on any environment.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            // Brand Label spec (material + method + placement). JSON blob.
            if (! Schema::hasColumn('quotations', 'brand_label_json')) {
                $table->json('brand_label_json')->nullable()->after('custom_pattern_image');
            }

            // Care/Size Label spec — same structure as Brand Label.
            if (! Schema::hasColumn('quotations', 'care_label_json')) {
                $table->json('care_label_json')->nullable()->after('brand_label_json');
            }

            // ONE shared label-design upload (file path on public disk OR an
            // external link). Optional — labels can be specced without artwork.
            if (! Schema::hasColumn('quotations', 'label_design_path')) {
                $table->string('label_design_path', 1000)->nullable()->after('care_label_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            foreach (['brand_label_json', 'care_label_json', 'label_design_path'] as $column) {
                if (Schema::hasColumn('quotations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};