<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6-A — CSR Hub backend
 *
 * `csr_activity_logs` is the cross-cutting CSR audit log. Distinct
 * from `stage_audit_logs` (which is per-stage and only tracks
 * production-stage transitions). This table captures CSR-specific
 * actions that don't belong to a production stage:
 *
 *   - inquiry.created
 *   - inquiry.converted_to_quotation
 *   - payment_proof.uploaded
 *   - payment.verified | payment.rejected
 *   - approval.requested | approval.responded
 *   - client_link.updated   (Messenger / GC / FB)
 *   - client_note.added
 *
 * `subject_type` + `subject_id` is a polymorphic pointer
 * (App\Models\Order, App\Models\Inquiry, etc.) so we don't need a
 * separate FK column per subject type.
 *
 * `order_id` and `client_id` are denormalized convenience columns —
 * the dashboard's "recent activity for this client/order" view filters
 * on these directly without resolving the polymorphic subject.
 *
 * NOTE: no `updated_at`. CSR activity rows are immutable once
 * written — they're a log, not a state record. Single `created_at`
 * timestamp only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csr_activity_logs', function (Blueprint $t) {
            $t->id();

            // Actor — nullable for system-triggered events
            $t->unsignedBigInteger('user_id')->nullable();
            $t->foreign('user_id', 'cal_user_id_fk')
                ->references('id')->on('users')->nullOnDelete();

            // Event name — e.g. 'inquiry.created', 'payment_proof.uploaded'
            // Convention: '{subject}.{verb_past}', lowercase, snake_case
            $t->string('action', 64);

            // Polymorphic subject pointer
            $t->string('subject_type')->nullable();     // App\Models\Order
            $t->unsignedBigInteger('subject_id')->nullable();

            // Denormalized for fast "activity for order X / client Y" queries
            $t->unsignedBigInteger('order_id')->nullable();
            $t->foreign('order_id', 'cal_order_id_fk')
                ->references('id')->on('orders')->nullOnDelete();

            $t->unsignedBigInteger('client_id')->nullable();
            $t->foreign('client_id', 'cal_client_id_fk')
                ->references('id')->on('clients')->nullOnDelete();

            // Human-readable one-liner ("INQ-2026-000012 → QUO-2026-000045")
            $t->string('summary', 255)->nullable();

            // Optional structured payload for the action (from/to status, etc.)
            $t->json('data')->nullable();

            // Single timestamp — immutable log
            $t->timestamp('created_at')->useCurrent();

            // Hot paths — all index-only scans for the dashboard's
            // "recent activity" panel + per-entity activity history view
            $t->index(['order_id',  'created_at'], 'cal_order_created_idx');
            $t->index(['client_id', 'created_at'], 'cal_client_created_idx');
            $t->index(['user_id',   'created_at'], 'cal_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csr_activity_logs');
    }
};
