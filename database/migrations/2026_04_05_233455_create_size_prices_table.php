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
        Schema::create('size_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shirt_id')->constrained('tshirt_types')->cascadeOnDelete();
            $table->foreignId('size_id')->constrained('tshirt_sizes')->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('size_prices');
    }
};
