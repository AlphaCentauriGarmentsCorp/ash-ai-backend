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
        if (Schema::hasTable('order_design_placements')) {
            return;
        }

        Schema::create('order_design_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_design_id')->constrained('order_designs')->cascadeOnDelete();
            $table->string('type');
            $table->text('mockup_image')->nullable();
            $table->json('pantones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_design_placements');
    }
};
