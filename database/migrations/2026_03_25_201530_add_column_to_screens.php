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
        Schema::table('screens', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->timestamp('last_used')->nullable()->after('last_maintenance');
            $table->string('status')->nullable()->after('total_use');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn('last_used');
            $table->dropColumn('status');
            $table->string('name')->nullable(false)->change();
        });
    }
};
