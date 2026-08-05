<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront catalogue.
 *
 * Squashed from create_products_table + the products half of
 * add_inventory_fields_to_products_and_variants (product_code, marketplace,
 * external_image_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();          // 'og-wave'

            // The ERP's own identifier, kept so rows can be matched both ways.
            $table->string('product_code', 24)->nullable()->unique();

            $table->string('name');                    // 'OG Wave Tee'
            $table->string('audience');                // men | women | unisex | accessories
            $table->string('type');                    // tee|hoodie|shorts|underwear|bag|socks
            $table->unsignedInteger('price');          // whole pesos
            $table->string('tag')->nullable();         // BEST SELLER | NEW | HEAVYWEIGHT | LAST FEW | ESSENTIAL | STAPLE
            $table->text('blurb')->nullable();
            $table->text('material')->nullable();      // fabric copy on the PDP
            $table->string('fit_name')->nullable();    // e.g. OVERSIZED CUT / RELAXED FIT / ONE SIZE
            $table->text('fit_desc')->nullable();
            $table->string('image_path')->nullable();  // real shot; null = use placeholder

            // The ERP's marketplace/image references, kept for two-way matching.
            $table->string('marketplace', 60)->nullable();
            $table->uuid('external_image_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            // Index names carry the storefront_ prefix too: SQLite (the test suite)
            // keeps index names in a single database-wide namespace, so an unprefixed
            // name could collide with one of the ERP's 122 migrations.
            $table->index(['audience', 'type'], 'storefront_products_aud_type_idx');
            $table->index('is_active', 'storefront_products_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_products');
    }
};
