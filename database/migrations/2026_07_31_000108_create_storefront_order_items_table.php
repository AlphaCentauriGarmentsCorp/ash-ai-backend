<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order lines. Squashed from create_order_items_table (unchanged after).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('storefront_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('storefront_products')->nullOnDelete();

            // Snapshot so historical orders survive catalog edits/deletes.
            $table->string('product_slug');
            $table->string('name');
            $table->string('size');
            $table->unsignedInteger('unit_price');  // whole pesos
            $table->unsignedInteger('qty');
            $table->unsignedInteger('line_total');
            $table->timestamps();

            $table->index('order_id', 'storefront_order_items_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_order_items');
    }
};
