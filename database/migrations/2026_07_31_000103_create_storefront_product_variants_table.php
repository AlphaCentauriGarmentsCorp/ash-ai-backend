<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One sellable SKU: a product in a single size.
 *
 * Squashed from create_product_variants_table + the variants half of
 * add_inventory_fields_to_products_and_variants. That migration reshaped the
 * catalogue into a warehouse-style inventory table so an external ERP can own stock
 * and availability, and this site can be the storefront that reflects it. The column
 * set mirrors the ERP's own inventory export, SKU for SKU.
 *
 * The important change is that one number became two:
 *
 *   on_hand   — physically in the warehouse. The ERP owns this.
 *   allocated — spoken for by orders that have not shipped. The storefront owns this.
 *   available — on_hand - allocated. What we are allowed to sell. Never stored.
 *
 * The original column was called `stock`; upstream renamed it to on_hand rather than
 * dropping it so no quantity was lost. Squashed, it is simply created as on_hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_product_variants', function (Blueprint $table) {
            $table->id();

            // Explicit target table: the inferred name would be `products`, which is
            // not ours.
            $table->foreignId('product_id')->constrained('storefront_products')->cascadeOnDelete();

            $table->string('size');                     // S|M|L|XL|2XL|OS
            $table->string('sku')->unique();            // REEFER-OG-WAVE-M

            $table->unsignedInteger('on_hand')->default(0);

            // Reserved by orders awaiting fulfilment. Checkout raises this instead of
            // cutting on_hand, so the warehouse count stays true until goods leave.
            $table->unsignedInteger('allocated')->default(0);
            $table->unsignedInteger('cancelled_qty')->default(0);

            // Shipping maths and the size table the storefront shows.
            $table->decimal('weight_grams', 8, 2)->nullable();
            $table->decimal('width_cm', 6, 2)->nullable();
            $table->decimal('length_cm', 6, 2)->nullable();

            // Where it physically sits. Internal only — never sent to the storefront.
            $table->string('shelf_location', 24)->nullable();
            $table->string('warehouse', 60)->nullable();
            $table->string('area', 60)->nullable();

            // Per-SIZE availability. products.is_active pulls a whole product; this
            // retires a single size, which is what the ERP's Status column does.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['product_id', 'size'], 'storefront_variant_product_size_uq');
            $table->index(['is_active', 'on_hand']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_product_variants');
    }
};
