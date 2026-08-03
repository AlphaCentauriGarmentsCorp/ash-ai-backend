<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product ratings.
 *
 * Only someone who bought the thing may rate it, and only once. The "bought it"
 * half is proved at write time by looking for an order_items row (see
 * ProductReviewController::assertPurchased) rather than stored here — copying a
 * "verified" flag onto the review would just be a second source of truth that
 * can drift from the orders table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('storefront_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('storefront_users')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');   // 1..5, enforced by the request rules
            $table->string('body', 1000)->nullable(); // optional written review
            $table->timestamps();

            // One review per person per product — the database decides, not the
            // controller. Re-rating updates the existing row.
            $table->unique(['product_id', 'user_id'], 'storefront_product_reviews_product_user_unique');

            // The public product page reads by product, newest first.
            $table->index(['product_id', 'created_at'], 'storefront_product_reviews_product_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_product_reviews');
    }
};
