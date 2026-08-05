<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which order lines a return covers, and how many of each.
 *
 * It points at order_items rather than at products: the price, name and size the
 * customer actually paid for are already frozen there, so the refund is a lookup
 * and never a re-quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained('storefront_return_requests')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('storefront_order_items')->cascadeOnDelete();

            $table->unsignedInteger('qty');
            $table->timestamps();

            // One row per order line per return. The controller merges a payload that
            // names the same line twice; this is the database saying the same thing.
            $table->unique(['return_request_id', 'order_item_id'], 'storefront_return_request_items_line_unique');

            // "How much of this line is already spoken for" is the hot read.
            $table->index('order_item_id', 'storefront_return_request_items_order_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_return_request_items');
    }
};
