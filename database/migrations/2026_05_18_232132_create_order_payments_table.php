<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6-A — CSR Hub backend
 *
 * `order_payments` is the per-payment record table. Distinct from
 * `payment_methods` (which is just a dropdown lookup of channels — GCash,
 * BPI, Cash, etc.). This table actually tracks individual payment
 * events with proofs and Finance verification flow.
 *
 * The verification flow is:
 *   waiting → CSR/client uploads proof → for_verification → Finance
 *   verifies → verified | rejected (with reason).
 *
 * CSR uploads proofs (gated by portal.csr). Finance verifies
 * (gated by action.verify-payment). Verification gating is enforced
 * in OrderPaymentService (not in the controller / not at the DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $t) {
            $t->id();

            // Order linkage (cascade delete — payment history dies with the order)
            $t->unsignedBigInteger('order_id');
            $t->foreign('order_id', 'op_order_id_fk')
                ->references('id')->on('orders')->cascadeOnDelete();

            // Payment classification
            // sample / down_payment / balance / full
            $t->string('payment_type', 16);

            // Amount in PHP (decimal 10,2 — same convention as Order subtotals)
            $t->decimal('amount', 10, 2);

            // Channel — nullable FK to existing payment_methods lookup
            $t->unsignedBigInteger('payment_method_id')->nullable();
            $t->foreign('payment_method_id', 'op_payment_method_fk')
                ->references('id')->on('payment_methods')->nullOnDelete();

            // Bank-side / GCash reference number from client
            $t->string('reference_number')->nullable();

            // Storage::disk('public') relative path to uploaded proof image/pdf
            $t->string('proof_path', 255)->nullable();

            // State machine status
            // waiting / for_verification / verified / rejected
            $t->string('status', 24)->default('waiting');

            // Upload-side audit
            $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $t->foreign('uploaded_by_user_id', 'op_uploaded_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $t->timestamp('uploaded_at')->nullable();

            // Verification-side audit
            $t->unsignedBigInteger('verified_by_user_id')->nullable();
            $t->foreign('verified_by_user_id', 'op_verified_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();

            // Free-text — populated only when status='rejected'
            $t->text('rejection_reason')->nullable();

            // Generic free-text notes (CSR can attach context)
            $t->text('notes')->nullable();

            $t->timestamps();

            // Hot path: dashboard "Pending Payments" — filter by order + status
            // and the global "for_verification" queue Finance sees.
            $t->index(['order_id', 'status'], 'op_order_status_idx');
            $t->index('status',               'op_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
