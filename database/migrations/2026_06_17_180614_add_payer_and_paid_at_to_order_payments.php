<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — payment capture fields.
 *
 * The Change Request's "Enter Payment" form (and the richer Dashboard
 * Pending-Approvals card) need to record WHO paid and WHEN the payment was
 * actually made — distinct from `uploaded_at`, which is only when the proof
 * was recorded in ASH. `order_payments` had neither column, so both were
 * unrecordable.
 *
 *   - payer_name : sino nagbayad (may differ from the client / account)
 *   - paid_at    : date & time the payment was actually made
 *
 * Both nullable: existing rows and auto-created gate stubs (ensureGatePayment)
 * have no payer/date. DISPLAY/record data only — no pricing or workflow impact.
 * Safe to re-run (guarded by hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('order_payments', 'payer_name')) {
                $table->string('payer_name')->nullable()->after('reference_number');
            }
            if (! Schema::hasColumn('order_payments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('payer_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            foreach (['paid_at', 'payer_name'] as $col) {
                if (Schema::hasColumn('order_payments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
