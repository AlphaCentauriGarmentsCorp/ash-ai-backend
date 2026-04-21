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
        try {
            Schema::table('apparel_pattern_prices', function (Blueprint $table) {
                $table->dropForeign(['apparel_type_id']);
                $table->dropForeign(['pattern_type_id']);
            });
        } catch (\Throwable $e) {
            // Fresh installs already use plain integer columns.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apparel_pattern_prices', function (Blueprint $table) {
            $table->foreign('apparel_type_id')->references('id')->on('apparel_types')->nullOnDelete();
            $table->foreign('pattern_type_id')->references('id')->on('pattern_types')->nullOnDelete();
        });
    }
};