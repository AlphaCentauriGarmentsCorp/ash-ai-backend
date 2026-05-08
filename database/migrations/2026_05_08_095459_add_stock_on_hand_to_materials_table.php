<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — adds a stock-on-hand counter to the materials table.
 *
 * The MR (Material Request) and PR (Purchase Request) workflows
 * decrement/increment this column as inventory flows in and out.
 *
 * Idempotent: skips silently if the column already exists.
 */
return new class extends Migration {

    public function up(): void
    {
        if (Schema::hasColumn('materials', 'stock_on_hand')) {
            return;
        }

        Schema::table('materials', function (Blueprint $table) {
            $table->decimal('stock_on_hand', 12, 2)->default(0)->after('price');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('materials', 'stock_on_hand')) {
            return;
        }

        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('stock_on_hand');
        });
    }
};
