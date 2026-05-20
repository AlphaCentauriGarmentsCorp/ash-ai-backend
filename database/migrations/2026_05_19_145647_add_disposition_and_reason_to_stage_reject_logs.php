<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7-B Bundle 1 — Extend stage_reject_logs.
 *
 * Two new columns:
 *
 *   `disposition`     enum('reject','repair') default 'reject'
 *                     Backfilled to 'reject' for every pre-existing row.
 *                     - reject = piece unsellable (scrap or customer-rework)
 *                     - repair = piece needs fixing but will still ship
 *
 *   `reject_reason_id` nullable FK to reject_reasons
 *                     Nullable because:
 *                       (a) older rows (pre-Bundle 1) have no taxonomy
 *                       (b) the table is also used by non-QA stages
 *                           where a reason taxonomy doesn't apply
 *
 * The original migration's comment block literally anticipated this
 * column — see the "reject may grow a `disposition` column" note.
 *
 * Both columns are added without dropping the existing `notes` freetext
 * field, which is still used for free-form context next to the taxonomy.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::table('stage_reject_logs', function (Blueprint $t) {
            $t->enum('disposition', ['reject', 'repair'])
                ->default('reject')
                ->after('quantity_pcs');

            $t->foreignId('reject_reason_id')
                ->nullable()
                ->after('disposition')
                ->constrained('reject_reasons')
                ->nullOnDelete();

            $t->index(['order_id', 'disposition']);
        });
    }

    public function down(): void
    {
        Schema::table('stage_reject_logs', function (Blueprint $t) {
            $t->dropIndex(['order_id', 'disposition']);
            $t->dropConstrainedForeignId('reject_reason_id');
            $t->dropColumn('disposition');
        });
    }
};
