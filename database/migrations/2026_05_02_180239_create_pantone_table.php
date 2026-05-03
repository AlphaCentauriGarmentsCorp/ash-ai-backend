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
        Schema::create('pantones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hexcolor');
            $table->string('pantone_code');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('pantones');
   }
};
