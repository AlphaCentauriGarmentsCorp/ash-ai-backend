<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('screen_checking_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('screen_checking_id')->constrained('screen_checkings')->cascadeOnDelete();

            $table->foreignId('placement_id')->constrained('order_design_placements')->cascadeOnDelete();
            $table->foreignId('screen_id')->constrained('screens')->cascadeOnDelete();
            $table->integer('color_index');
            $table->string('pantone')->nullable();

            $table->boolean('clean')->default(false);
            $table->boolean('no_damage')->default(false);
            $table->boolean('emulsion_ok')->default(false);
            $table->boolean('verified')->default(false);

            $table->text('issues')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screen_checking_items');
    }
};
