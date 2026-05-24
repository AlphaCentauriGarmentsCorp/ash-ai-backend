<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add an editable MOQ (minimum order quantity) to add-ons.
 *
 * Owner rule: label / packaging add-ons are quoted per piece, but there is a
 * supplier minimum (default 50 pcs). If an order has FEWER pieces than the
 * MOQ, the client is still charged the full minimum batch
 * (qty < moq  ->  charge = price_per_piece × moq), because that is what the
 * supplier charges regardless. At or above the MOQ it is simple per-piece
 * (price_per_piece × qty).
 *
 * MOQ is per-add-on and Superadmin-editable, because the owner sometimes
 * lowers it. Default 50 matches the current sheet.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('addons')) {
            return;
        }

        Schema::table('addons', function (Blueprint $table) {
            if (! Schema::hasColumn('addons', 'moq')) {
                // Minimum order quantity (pcs). Default 50 per the owner's sheet.
                $table->unsignedInteger('moq')->default(50)->after('price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('addons')) {
            return;
        }

        Schema::table('addons', function (Blueprint $table) {
            if (Schema::hasColumn('addons', 'moq')) {
                $table->dropColumn('moq');
            }
        });
    }
};