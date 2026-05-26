<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue 12 — Quotation status transition audit log.
 *
 * One immutable row per quotation status change (who, when, old → new, why).
 * Satisfies the blueprint's "every action logged: who, time, old value, new
 * value" requirement for the quotation lifecycle.
 *
 * Mirrors the established stage_audit_logs pattern: rows are immutable
 * (created_at only, no updated_at — enforced on the model via UPDATED_AT=null).
 * Written by QuotationService on every transition (changeStatus + the existing
 * confirmAndConvert).
 *
 * Guarded so it is safe to run on any environment.
 */
return new class extends Migration {

    public function up(): void
    {
        if (Schema::hasTable('quotation_status_logs')) {
            return;
        }

        Schema::create('quotation_status_logs', function (Blueprint $t) {
            $t->id();

            $t->foreignId('quotation_id')
                ->constrained('quotations')
                ->cascadeOnDelete();

            // Nullable because some transitions (e.g. a system auto-expire job)
            // may not have an acting user.
            $t->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Status strings: Draft | Pending | Sent | Approved | Converted |
            // Rejected | Expired. from_status is null only for the very first
            // (creation) row, if one is written.
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32)->index();

            // Optional reason / context entered by the CSR (e.g. why rejected).
            $t->text('notes')->nullable();

            // For the 'Sent' transition: did the client email actually go out?
            // null  = not applicable (non-Sent transition)
            // true  = email sent successfully
            // false = transition succeeded but email failed (reason in notes)
            $t->boolean('email_sent')->nullable();

            // Immutable — only created_at, no updated_at.
            $t->timestamp('created_at')->nullable()->index();

            $t->index(['quotation_id', 'to_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_status_logs');
    }
};