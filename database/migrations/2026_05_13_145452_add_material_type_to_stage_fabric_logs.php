<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-E — Add material_type to stage_fabric_logs.
 *
 * Sewer tracks multiple material types per stage (main fabric, rib/trim,
 * thread, interfacing, other). Instead of creating a separate table, we
 * tag each fabric log row with a material_type. Existing Cutter logs
 * default to 'main_fabric' (null preserved for legacy rows = "unspecified").
 *
 * Allowed values (enforced in service):
 *   - main_fabric (default for Cutter)
 *   - rib_trim
 *   - thread
 *   - interfacing
 *   - other
 *   - waste (used when logging waste separately)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stage_fabric_logs', function (Blueprint $t) {
            $t->string('material_type', 32)
                ->nullable()
                ->after('logged_by_user_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('stage_fabric_logs', function (Blueprint $t) {
            $t->dropIndex(['material_type']);
            $t->dropColumn('material_type');
        });
    }
};
