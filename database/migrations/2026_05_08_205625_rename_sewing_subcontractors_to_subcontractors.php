<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — rename `sewing_subcontractors` → `subcontractors`.
 *
 * The existing table holds vendors used for sewing only. Phase 4 expands
 * this to cover cutting and printing too, so the name was misleading.
 *
 * Strategy:
 *   1. Rename the table (keeps existing data + FK references)
 *   2. Add a `service_type` column ('sewing' | 'cutting' | 'printing' |
 *      'multiple') so vendor records can declare what they do. Defaults
 *      to 'sewing' to match the existing semantics — no data is lost.
 *
 * The PHP `SewingSubcontractor` Eloquent model is updated to point at
 * the new table name via $table; we deliberately leave the class name
 * alone to avoid touching a wide blast radius of frontend pages,
 * controllers, and resources that import it.
 *
 * Idempotent: skips if the rename already happened.
 */
return new class extends Migration {

    public function up(): void
    {
        // Skip if Phase 4 already ran here.
        if (Schema::hasTable('subcontractors')) {
            return;
        }

        if (! Schema::hasTable('sewing_subcontractors')) {
            // Fresh installs that haven't created the legacy table yet —
            // create the new one directly with the modern shape.
            Schema::create('subcontractors', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('address');
                $t->decimal('rate_per_pcs', 10, 2);
                $t->string('contact_number')->nullable();
                $t->string('email')->nullable();
                $t->string('service_type', 32)->default('sewing');
                $t->timestamps();
            });
            return;
        }

        // Rename existing table.
        Schema::rename('sewing_subcontractors', 'subcontractors');

        // Add service_type if not present (idempotent).
        if (! Schema::hasColumn('subcontractors', 'service_type')) {
            Schema::table('subcontractors', function (Blueprint $t) {
                $t->string('service_type', 32)->default('sewing')->after('email');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subcontractors')) {
            return;
        }

        // Best-effort drop the new column before renaming back.
        if (Schema::hasColumn('subcontractors', 'service_type')) {
            Schema::table('subcontractors', function (Blueprint $t) {
                $t->dropColumn('service_type');
            });
        }

        // Don't blindly rename back if the legacy table somehow still
        // exists — would conflict.
        if (! Schema::hasTable('sewing_subcontractors')) {
            Schema::rename('subcontractors', 'sewing_subcontractors');
        }
    }
};
