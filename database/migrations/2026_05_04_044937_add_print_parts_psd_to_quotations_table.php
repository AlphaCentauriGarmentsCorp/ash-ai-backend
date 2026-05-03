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
    Schema::table('quotations', function (Blueprint $table) {
        // Add the print_parts_psd column to store the PSD file path
        $table->string('print_parts_psd')->nullable();
        });
    }

    public function down(): void
   {
    Schema::table('quotations', function (Blueprint $table) {
        // Drop the column if we rollback
        $table->dropColumn('print_parts_psd');
        });
    }
};
