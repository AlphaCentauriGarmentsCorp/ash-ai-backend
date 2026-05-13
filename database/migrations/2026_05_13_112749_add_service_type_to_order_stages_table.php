<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-D — Add service_type to order_stages.
 *
 * Tracks whether a stage is being handled in-house (by internal staff
 * using our portals) or subcontracted (sent to an outside vendor,
 * tracked via stage_subcontract_assignments).
 *
 * Default 'in_house' for backward compatibility — existing stages
 * are assumed in-house unless explicitly flipped.
 *
 * Only certain stage types are flippable (sample_creation, mass_production,
 * screen_making, quality_control, packing). The flippable list is
 * enforced in StageServiceTypeService, not at the DB layer, so future
 * additions don't require migrations.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_stages', function (Blueprint $t) {
            $t->string('service_type', 16)
                ->default('in_house')
                ->after('status')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('order_stages', function (Blueprint $t) {
            $t->dropIndex(['service_type']);
            $t->dropColumn('service_type');
        });
    }
};
