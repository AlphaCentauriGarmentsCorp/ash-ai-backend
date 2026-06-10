<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Swatch favourites-by-frequency — actually add fabric_swatches.pick_count.
 *
 * A prior `add_pick_count_to_fabric_swatches` migration was recorded as run but
 * left the column absent (empty stub), so recordPick()'s UPDATE errors. This
 * guarded migration is idempotent: it adds the column only if missing, so it is
 * safe regardless of the current state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fabric_swatches', 'pick_count')) {
            return; // already present — nothing to do
        }

        Schema::table('fabric_swatches', function (Blueprint $table) {
            $table->unsignedInteger('pick_count')->default(0);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fabric_swatches', 'pick_count')) {
            return;
        }

        Schema::table('fabric_swatches', function (Blueprint $table) {
            $table->dropColumn('pick_count');
        });
    }
};