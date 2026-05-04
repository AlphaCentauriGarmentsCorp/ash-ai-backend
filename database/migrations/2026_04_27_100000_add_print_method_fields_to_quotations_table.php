<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('print_method_id')
                ->nullable()
                ->after('apparel_neckline_id')
                ->constrained('print_methods')
                ->nullOnDelete();
            $table->string('special_print')->nullable()->after('print_method_id');
            $table->string('print_area')->nullable()->after('special_print');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('print_method_id');
            $table->dropColumn(['special_print', 'print_area']);
        });
    }
};
