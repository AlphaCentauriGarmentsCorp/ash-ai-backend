<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the Graphic Artist design-review fields to quotations (Issue 8, Sec. 5).
 *
 * The CSR uploads design files on the quotation (the existing per-part upload),
 * then sends the quotation to the Graphic Artist for a colours/clarity check.
 * The GA records a verdict (Pending GA / GA Approved / Needs New File) plus the
 * colour count that feeds back into pricing, and an optional note to the CSR.
 *
 * - design_review_status  null = not submitted for review yet.
 * - design_color_count    GA's verified colour count (pooled, silkscreen).
 *                         Stage D wires this back into the pricing recompute.
 * - design_review_note    free-text result for the CSR ("back logo blurry").
 * - design_reviewed_by    the GA/Superadmin who last set the verdict.
 * - design_reviewed_at    when the verdict was last set.
 *
 * All guarded with hasColumn() so it is safe to re-run on any environment.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'design_review_status')) {
                // 'Pending GA' | 'GA Approved' | 'Needs New File'. Null until
                // the CSR sends the quotation to the GA.
                $table->string('design_review_status', 32)->nullable()->after('status');
            }
            if (! Schema::hasColumn('quotations', 'design_color_count')) {
                $table->unsignedInteger('design_color_count')->nullable()->after('design_review_status');
            }
            if (! Schema::hasColumn('quotations', 'design_review_note')) {
                $table->text('design_review_note')->nullable()->after('design_color_count');
            }
            if (! Schema::hasColumn('quotations', 'design_reviewed_by')) {
                $table->foreignId('design_reviewed_by')
                    ->nullable()
                    ->after('design_review_note')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('quotations', 'design_reviewed_at')) {
                $table->timestamp('design_reviewed_at')->nullable()->after('design_reviewed_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'design_reviewed_by')) {
                // Drop the FK constraint before the column (SQLite-safe guard).
                try {
                    $table->dropForeign(['design_reviewed_by']);
                } catch (\Throwable $e) {
                    // no-op: constraint may not exist on SQLite test runs
                }
            }
            foreach ([
                'design_reviewed_at',
                'design_reviewed_by',
                'design_review_note',
                'design_color_count',
                'design_review_status',
            ] as $column) {
                if (Schema::hasColumn('quotations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};