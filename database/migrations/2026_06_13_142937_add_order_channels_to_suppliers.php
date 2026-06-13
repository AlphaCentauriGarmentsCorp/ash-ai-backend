<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue 20 — Supplier order channels.
 *
 * Adds two columns to `suppliers` so the Material Prep / Purchaser portal can
 * surface one-click "order channel" quick-buttons on a Purchase Request:
 *
 *   order_channels  json    — array of { type, label, url, is_primary }.
 *                             Exactly one entry is flagged is_primary (the
 *                             SupplierService normalizes this on write).
 *   is_incomplete   boolean — set TRUE by the PR quick-add path so a supplier
 *                             created on-the-fly is flagged "complete later".
 *
 * Idempotent (hasColumn guards) so it is safe to re-run on the live DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'order_channels')) {
                $table->json('order_channels')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('suppliers', 'is_incomplete')) {
                $table->boolean('is_incomplete')->default(false)->after('order_channels');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            foreach (['is_incomplete', 'order_channels'] as $column) {
                if (Schema::hasColumn('suppliers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
