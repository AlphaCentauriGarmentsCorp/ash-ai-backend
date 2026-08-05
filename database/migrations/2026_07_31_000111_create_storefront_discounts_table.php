<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher codes.
 *
 * The row is the only thing that knows what a code is worth — the client sends
 * a code and never an amount. Money is whole pesos, matching every other total
 * in the app; `value` doubles as percent points when type is 'percent'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_discounts', function (Blueprint $table) {
            $table->id();

            // Stored uppercase (Discount::normalize) so the unique index is the
            // case-insensitivity, rather than a collation we have to trust.
            $table->string('code', 40)->unique();

            $table->string('type');                             // percent | fixed
            $table->unsignedInteger('value');                   // percent points, or whole pesos
            $table->unsignedInteger('min_subtotal')->default(0);

            // Both nullable: an always-on code has neither end of the window.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->unsignedInteger('max_uses')->nullable();    // null = unlimited
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_user_limit')->nullable(); // null = unlimited per account

            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_discounts');
    }
};
