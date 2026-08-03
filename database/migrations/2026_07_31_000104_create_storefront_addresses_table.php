<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer address book. Squashed from create_addresses_table (unchanged after).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_addresses', function (Blueprint $table) {
            $table->id();

            // Explicit target table: the inferred name would be `users`, which is the
            // ERP's staff table.
            $table->foreignId('user_id')->constrained('storefront_users')->cascadeOnDelete();

            $table->string('label')->nullable();        // 'Home', 'Work'
            $table->string('name');                     // recipient
            $table->string('phone');
            $table->string('street');                   // line
            $table->string('barangay')->nullable();
            $table->string('city');
            $table->string('province');
            $table->string('region')->nullable();
            $table->string('postal', 10)->nullable();
            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_default_billing')->default(false);
            $table->timestamps();

            $table->index('user_id', 'storefront_addresses_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_addresses');
    }
};
