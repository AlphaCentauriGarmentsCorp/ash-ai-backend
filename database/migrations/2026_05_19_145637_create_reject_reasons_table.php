<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7-B Bundle 1 — Reject reason lookup table.
 *
 * Per the QA/Packer portal spec, rejects are categorised against a
 * fixed 7-value taxonomy (fabric_issue, print_issue, sewing_issue,
 * stain, wrong_size, wrong_label, damaged).
 *
 * Stored as a lookup table (not a PHP enum) to match the codebase
 * pattern set by service_types / print_methods / pattern_types,
 * and to allow future reason additions without a migration.
 *
 * `slug`        is the machine-readable key the API uses.
 * `label`       is what the packer sees in the dropdown.
 * `is_fabric`   shortcut flag — when set, a reject of this reason
 *               additionally notifies the Cutter (per PDF §6 rule
 *               "if reject due to fabric: notify Cutter").
 * `display_order` lets the seeder control dropdown ordering.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('reject_reasons', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 64)->unique();
            $t->string('label', 128);
            $t->boolean('is_fabric')->default(false);
            $t->unsignedSmallInteger('display_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->index('active');
            $t->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reject_reasons');
    }
};
