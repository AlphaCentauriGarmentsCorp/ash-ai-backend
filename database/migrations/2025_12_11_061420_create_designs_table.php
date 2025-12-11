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
            $table->bigInteger('artist_id')->unsigned()->index()->nullable();
            $table->foreign('artist_id')->references('id')->on('users')->onDelete('cascade'); 

            $table->bigInteger('po_number')->unsigned()->index()->nullable();
            $table->foreign('po_number')->references('id')->on('orders')->onDelete('cascade');

            $table->string('design_name');
       
            $table->bigInteger('printing_method')->unsigned()->index()->nullable();
            $table->foreign('printing_method')->references('id')->on('type_printing_method')->onDelete('cascade');
            
            $table->string('resolution');
            $table->string('color_count');
            $table->string('mockup_files');
            $table->string('production_diles');
            $table->string('design_placements');
            $table->string('color_palette');
            $table->string('notes');
            $table->string('status');
            $table->integer('version');
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
