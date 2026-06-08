<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-add `brand` to the orders table.
 *
 * `2026_05_01_recreate_orders_table` dropped the legacy `brand` column (among
 * others) and a later migration re-added deadline/priority but NOT brand — so
 * the apparel brand the Add/Edit Order form collects had nowhere to land and
 * was silently dropped. This restores it (nullable). Safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'brand')) {
                $table->string('brand')->nullable()->after('client_brand');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'brand')) {
                $table->dropColumn('brand');
            }
        });
    }
};
