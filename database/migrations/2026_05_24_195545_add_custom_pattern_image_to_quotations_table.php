<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `custom_pattern_image` to quotations (Blueprint Issue 6).
 *
 * When a CSR selects a "Custom" fit/pattern, they can attach the client's
 * drawn/reference pattern so the Graphic Artist and production can see what
 * was agreed. Stores either an uploaded file path OR an external link
 * (Google Drive / Canva), mirroring the print-parts upload pattern. A single
 * string column is enough — one reference per quotation.
 *
 * Guarded with hasColumn() so it is safe to run on any environment.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'custom_pattern_image')) {
                // File path (storage/public) or external link to the client's
                // custom pattern reference. Nullable — only set for custom fits.
                $table->string('custom_pattern_image')->nullable()->after('print_parts_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'custom_pattern_image')) {
                $table->dropColumn('custom_pattern_image');
            }
        });
    }
};