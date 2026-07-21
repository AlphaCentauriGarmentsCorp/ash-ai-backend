<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materials & Suppliers — relax the materials schema so that only the
 * material NAME is mandatory (matches the reworked Add Materials form).
 *
 *   supplier_id   : drop the FK constraint + make NULLABLE.
 *                   (ASH convention going forward: no DB-level FKs.)
 *   material_type : make NULLABLE.
 *
 * unit / price / minimum / lead / notes are already nullable.
 *
 * Idempotent + driver-aware:
 *   - dropForeign only runs on MySQL and is wrapped so a missing/already
 *     dropped constraint is a no-op (safe to re-run on the live DB).
 *   - SQLite (Pest hand-built schemas) has no named FK to drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Drop the supplier_id foreign key (MySQL only; safe if absent).
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            try {
                Schema::table('materials', function (Blueprint $table) {
                    $table->dropForeign(['supplier_id']);
                });
            } catch (\Throwable $e) {
                // Constraint already dropped or never created — continue.
            }
        }

        // 2) Make supplier_id + material_type nullable.
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->change();
            $table->string('material_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revert material_type to NOT NULL. supplier_id is intentionally left
        // nullable on rollback (re-adding a NOT NULL FK would fail on any
        // supplier-less rows, and ASH no longer uses DB-level FKs).
        Schema::table('materials', function (Blueprint $table) {
            $table->string('material_type')->nullable(false)->change();
        });
    }
};
