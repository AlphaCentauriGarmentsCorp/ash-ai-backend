<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cart per account, so it follows the shopper between devices instead of
 * living in one browser's localStorage.
 *
 * Guest carts are deliberately not modelled: adding to the cart requires
 * signing in, so every cart belongs to a known user. If guest checkout ever
 * comes back, this is where a nullable session column would go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_carts', function (Blueprint $table) {
            $table->id();

            // unique(): one open cart per account. The shopper has "a cart", not
            // a pile of them — enforced by the database, not by hopeful code.
            // Explicit target table: the inferred name would be `users`, the ERP's.
            $table->foreignId('user_id')->unique()->constrained('storefront_users')->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_carts');
    }
};
