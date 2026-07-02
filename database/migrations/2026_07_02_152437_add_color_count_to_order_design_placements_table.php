<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GA Portal CP1 — GA-writable print placements.
 *
 * color_count is the number of Pantone slots for the placement. It is
 * seeded from the quotation print part's color_count (the "Color#" of
 * the legacy portal) but the artist may raise/lower it. Kept separate
 * from the pantones JSON array so empty (not-yet-filled) slots survive
 * a reload without storing placeholder entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_design_placements')) {
            return;
        }
        if (Schema::hasColumn('order_design_placements', 'color_count')) {
            return;
        }

        Schema::table('order_design_placements', function (Blueprint $table) {
            $table->unsignedTinyInteger('color_count')->nullable()->after('mockup_image');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_design_placements')) {
            return;
        }
        if (! Schema::hasColumn('order_design_placements', 'color_count')) {
            return;
        }

        Schema::table('order_design_placements', function (Blueprint $table) {
            $table->dropColumn('color_count');
        });
    }
};
