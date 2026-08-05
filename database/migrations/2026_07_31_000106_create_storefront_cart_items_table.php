<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Squashed from create_cart_items_table + add_selected_to_cart_items_table.
 *
 * Note what is NOT here: no name, no unit_price, no line_total.
 *
 * That is the difference between a cart and an order. order_items snapshots
 * price and name so a past order never changes. A cart is the opposite — it
 * must show today's price, so it stores only a pointer to the variant and a
 * quantity, and every price is read live from the catalog. Copying the price
 * in here is how a cart ends up quoting a total the checkout won't honour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('storefront_carts')->cascadeOnDelete();

            // The variant pins product AND size in one column. cascadeOnDelete,
            // not nullOnDelete: a cart line pointing at a deleted variant means
            // nothing, unlike an order line which must survive as history.
            $table->foreignId('product_variant_id')->constrained('storefront_product_variants')->cascadeOnDelete();

            $table->unsignedInteger('qty');

            /*
             * Which lines are actually being checked out.
             *
             * A cart doubles as a save-for-later list: you keep six things in it and
             * buy two this payday. So selection is a property of the line, and it has
             * to live in the database — tick two boxes on your phone, open the laptop,
             * and the same two are still ticked.
             *
             * Default true: something you just added is something you intend to buy,
             * so making people tick it afterwards would be busywork.
             */
            $table->boolean('selected')->default(true);

            $table->timestamps();

            // Adding the same size twice bumps the quantity; it never makes a
            // second line. The database guarantees that, not the controller.
            $table->unique(['cart_id', 'product_variant_id'], 'storefront_cart_items_cart_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_cart_items');
    }
};
