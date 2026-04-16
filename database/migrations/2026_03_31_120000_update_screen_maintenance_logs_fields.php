<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('screen_maintenance_logs')) {
            return;
        }

        Schema::table('screen_maintenance_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('screen_maintenance_logs', 'notes')) {
                $table->string('notes')->nullable()->after('maintenance_type');
            }

            if (!Schema::hasColumn('screen_maintenance_logs', 'materials_used')) {
                $table->string('materials_used')->nullable()->after('notes');
            }

            if (Schema::hasColumn('screen_maintenance_logs', 'status')) {
                $table->dropColumn('status');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('screen_maintenance_logs')) {
            return;
        }

        Schema::table('screen_maintenance_logs', function (Blueprint $table) {
            if (Schema::hasColumn('screen_maintenance_logs', 'materials_used')) {
                $table->dropColumn('materials_used');
            }

            if (Schema::hasColumn('screen_maintenance_logs', 'notes')) {
                $table->dropColumn('notes');
            }

            if (!Schema::hasColumn('screen_maintenance_logs', 'status')) {
                $table->string('status')->default('Pending');
            }
        });
    }
};
