<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Add the new fields for length, width, and price per square inch
            $table->decimal('length', 8, 2)->nullable();  // Length in square inches
            $table->decimal('width', 8, 2)->nullable();   // Width in square inches
            $table->decimal('price_per_square_inch', 10, 2)->nullable();  // Price per square inch
      });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Remove the fields if we rollback the migration
            $table->dropColumn('length');
            $table->dropColumn('width');
            $table->dropColumn('price_per_square_inch');
        });
    }
};
