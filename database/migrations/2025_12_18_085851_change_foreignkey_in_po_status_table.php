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
        Schema::table('po_statuses', function (Blueprint $table) {
            $table->foreign('po_id')
                  ->references('id')
                  ->on('orders')
                  ->cascadeOnDelete();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('po_status', function (Blueprint $table) {
            $table->dropForeign(['po_id']);
        });
    }
};
