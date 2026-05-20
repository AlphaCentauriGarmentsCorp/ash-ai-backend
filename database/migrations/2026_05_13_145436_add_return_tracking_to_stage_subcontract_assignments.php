<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-E — Add expected_return_at + turnover_method to SCA.
 *
 * The Sewer mockup's "Subcontract Tracking" section needs these:
 *   - expected_return_at: when the vendor is supposed to deliver back
 *   - turnover_method: how the work moves (e.g., Lalamove, Grab Express,
 *     personal pickup, vendor delivery)
 *
 * Both nullable — vendors aren't always committed to a hard return date,
 * and turnover method is sometimes decided just-in-time.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stage_subcontract_assignments', function (Blueprint $t) {
            $t->timestamp('expected_return_at')->nullable()->after('returned_at');
            $t->string('turnover_method', 64)->nullable()->after('expected_return_at');
        });
    }

    public function down(): void
    {
        Schema::table('stage_subcontract_assignments', function (Blueprint $t) {
            $t->dropColumn(['expected_return_at', 'turnover_method']);
        });
    }
};
