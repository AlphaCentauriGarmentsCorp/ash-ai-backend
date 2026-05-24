<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PER-SIZE BASE PRICING (Blueprint Section 3.2 / Issue 6).
 *
 * Base price differs per size (S, M, L, XL, 2XL, 3XL ...) because fabric
 * consumption differs, and differs per template. Until now apparel_pattern_prices
 * stored a SINGLE `price` per (apparel × pattern) combination, so per-size
 * variation only came from the frontend's manual unit_price.
 *
 * Design A (chosen for safety + simplicity): keep one row per
 * (apparel × pattern) — so existing apparel_pattern_price_id references stay
 * valid — and store the per-size prices as JSON on that row. The CSR still
 * picks ONE "Hoodie / Standard" combination; the engine then looks up the
 * correct price per size from this JSON (Paraan 1).
 *
 * Shape:
 *   size_prices = { "Small": 650, "Medium": 650, "Large": 680, ... }
 *
 * The legacy `price` column is kept as a fallback (used when a size has no
 * entry in size_prices), so nothing breaks for existing data.
 *
 * Sizes are manageable: Superadmin can add/remove size keys (XS, 4XL, etc.)
 * via the Settings grid — no schema change needed since it's JSON.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('apparel_pattern_prices')) {
            return;
        }

        if (Schema::hasColumn('apparel_pattern_prices', 'size_prices')) {
            return;
        }

        Schema::table('apparel_pattern_prices', function (Blueprint $table) {
            // Nullable so existing rows are untouched until Superadmin sets
            // per-size prices. JSON map of size name => base price.
            $table->json('size_prices')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('apparel_pattern_prices', 'size_prices')) {
            Schema::table('apparel_pattern_prices', function (Blueprint $table) {
                $table->dropColumn('size_prices');
            });
        }
    }
};