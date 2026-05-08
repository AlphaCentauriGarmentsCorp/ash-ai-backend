<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Stage transition audit log.
 *
 * One row per stage state change. Fed by hooks in OrderStagesService.
 *
 * `duration_seconds` and `business_duration_seconds` are populated only
 * on terminal-transition rows (e.g., the row that flips a stage to
 * 'completed'). They store wall-clock and business-hours durations
 * respectively, computed from the matching 'started' row for the same
 * order_stage_id. Storing both lets reports stay cheap — no need to
 * recompute on every dashboard load.
 *
 * Audit rows are immutable: only created_at, no updated_at.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('stage_audit_logs', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->foreignId('order_stage_id')
                ->constrained('order_stages')
                ->cascadeOnDelete();

            // Nullable because some transitions (e.g., system auto-promote)
            // don't have a user.
            $t->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Action verb: started | completed | delayed | on_hold |
            // resumed | for_approval | cancelled
            $t->string('action', 32)->index();

            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32)->nullable();

            // Duration columns — populated on terminal transitions only.
            // Wall-clock (real elapsed time, includes overnight & weekends).
            $t->unsignedBigInteger('duration_seconds')->nullable();
            // Business hours (Mon-Sat 08-18 by default — see config/work_calendar.php).
            $t->unsignedBigInteger('business_duration_seconds')->nullable();

            $t->text('notes')->nullable();

            // Immutable — only created_at, no updated_at.
            $t->timestamp('created_at')->nullable()->index();

            $t->index(['order_id', 'action']);
            $t->index(['order_stage_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_audit_logs');
    }
};
