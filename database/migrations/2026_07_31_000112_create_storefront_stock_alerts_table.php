<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Back-in-stock alerts — "tell me when my size is back".
 *
 * One row per (account, variant), enforced by the database rather than by a
 * controller hoping: a double-tapped button is a race the DB has to lose for us,
 * not a check-then-insert gap. cascadeOnDelete both ways, like favorites — an
 * alert is meaningless once either the account or the variant is gone.
 *
 * notified_at is the whole state machine: null means still waiting, stamped means
 * this restock has already been mailed about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('storefront_users')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('storefront_product_variants')->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'product_variant_id'], 'storefront_stock_alerts_user_variant_unique');

            // The account's list reads by user, newest first.
            $table->index(['user_id', 'created_at'], 'storefront_stock_alerts_user_created_idx');

            // The notify job scans for everything still waiting, across all accounts.
            $table->index(['notified_at', 'product_variant_id'], 'storefront_stock_alerts_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_stock_alerts');
    }
};
