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
        Schema::create('warehouse_materials', function (Blueprint $table) {
            $table->id();
            $table->string('material_name', length: 50);
            $table->string('brand', length: 50);
            $table->string('category', length: 50);
            $table->string('type', length: 50);
            $table->string('unit', length: 50);
            $table->decimal('quantity', total: 8, places: 2);
            $table->decimal('cost_per_unit',total: 8, places: 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_materials');
    }
};
