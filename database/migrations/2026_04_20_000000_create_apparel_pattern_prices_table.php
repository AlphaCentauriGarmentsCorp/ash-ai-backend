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
        Schema::create('apparel_pattern_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('apparel_type_id')->nullable();
            $table->unsignedBigInteger('pattern_type_id')->nullable();
            $table->string('apparel_type_name');
            $table->string('pattern_type_name');
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->unique(['apparel_type_name', 'pattern_type_name'], 'app_pattern_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apparel_pattern_prices');
    }
};

