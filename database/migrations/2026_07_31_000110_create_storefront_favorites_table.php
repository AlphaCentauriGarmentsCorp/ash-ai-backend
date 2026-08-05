<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wishlist — products an account has favorited.
 *
 * A join table, so it is naturally a set: one row per (user, product), and the
 * unique index makes favoriting the same thing twice a no-op rather than a
 * duplicate. cascadeOnDelete both ways: a favorite is meaningless once either
 * the account or the product is gone, unlike an order line which is history.
 *
 * No model — Customer::favoriteProducts() reaches it through belongsToMany, and that
 * relation must name `storefront_favorites` explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('storefront_users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('storefront_products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'product_id'], 'storefront_favorites_user_product_unique');

            // The account's wishlist reads by user, newest first.
            $table->index(['user_id', 'created_at'], 'storefront_favorites_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_favorites');
    }
};
