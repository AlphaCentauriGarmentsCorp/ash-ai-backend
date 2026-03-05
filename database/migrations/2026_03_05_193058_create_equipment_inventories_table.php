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
        Schema::create('equipment_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('equipment_location')->onDelete('cascade');
            $table->string('sku')->unique();
            $table->string('name');
            $table->integer('quantity')->default(0);
            $table->string('color')->nullable();
            $table->string('model')->nullable();
            $table->string('material')->nullable();
            $table->string('price')->nullable();
            $table->string('penalty')->nullable();
            $table->text('design')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->text('receipt')->nullable();
            $table->string('qr_code')->nullable();
            $table->string('status')->default('Available');
            $table->integer('in_use')->default(0);
            $table->integer('missing')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipment_inventory');
    }
};
