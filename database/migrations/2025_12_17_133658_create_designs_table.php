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
        Schema::create('designs', function (Blueprint $table) {
            $table->id();
            $table->BigInteger('artist_id');
            $table->BigInteger('po_number');
            $table->string('design_name');
            $table->BigInteger('type_printing_method');
            $table->string('resolution');
            $table->string('color_count')->nullable();
            $table->text('mockup_files')->nullable();
            $table->text('production_files')->nullable();
            $table->text('design_placements')->nullable();
            $table->text('color_palette')->nullable();
            $table->string('notes')->nullable();
            $table->string('status')->nullable();
            $table->integer('version')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('designs');
    }
};
